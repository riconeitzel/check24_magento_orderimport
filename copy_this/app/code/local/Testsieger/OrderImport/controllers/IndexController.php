<?php
    /**
     * @copyright (C) 2013 Testsieger Portal AG
     *
     * @license GPL 3:
     * This program is free software: you can redistribute it and/or modify
     * it under the terms of the GNU General Public License as published by
     * the Free Software Foundation, either version 3 of the License, or
     * (at your option) any later version.
     *
     * This program is distributed in the hope that it will be useful,
     * but WITHOUT ANY WARRANTY; without even the implied warranty of
     * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
     * GNU General Public License for more details.
     *
     * You should have received a copy of the GNU General Public License
     * along with this program.  If not, see <http://www.gnu.org/licenses/>.
     *
     * @package Testsieger.de OpenTrans Connector
     */

    class Testsieger_OrderImport_IndexController extends Mage_Core_Controller_Front_Action {

        const RS_OPENTRANS_EXIT_NONEWFILES = 'Keine neuen Bestelldateien.';
        const RS_OPENTRANS_EXIT_OK = 'Erfolgreich abgeschlossen.';
        const RS_OPENTRANS_EXIT_ERROR = 'Fehler.';
        const LOCK_TIME = 600;

        /**
         * @var resource FTP-Stream
         */
        protected $_ftp_stream = NULL;

        /**
         * FRONTEND CONTROLLER: MAIN ENTRY POINT TO INITIATE IMPORT.
         *
         */
        public function import_ordersAction() {

            $config = Mage::getStoreConfig('OrderImport_options/access');
            $delivery_config = Mage::getStoreConfig('OrderImport_options/shipping');

            echo '<a href="javascript:history.back()">Back / Zur&uuml;ck</a><br>';

            if (!$config['orderimport_access_ftpuser'] || $this->getRequest()->getParam('key') != $config['orderimport_access_ftpuser']) {
                die('Wrong Username / Falscher Benutzername');
            }

            if(!isset($delivery_config['orderimport_payment_classmethodid'])) {
                die('No standard payment type defined. Please choose a payment type in your settings. / Keine Standard-Zahlungsart definiert. Bitte w&auml;hlen Sie eine Zahlungsart bei den Einstellungen aus.');
            }

            $include = dirname(__FILE__) . '/../Model/opentrans/opentrans.php';
            require_once($include);

            $this->import_orders($config);

            die('<span style="color:#090"><b><u>OK! Exit now. </u></b></span>');

        }

        /**
         * MAIN ENTRY POINT FOR NON SHOP-SPECIFIC PROCESSING OF XML-FILES
         */
        public function import_orders(array $config) {

            // Check for concurrency.
            $this->concurrency_lock_check();
            $this->concurrency_lock_set();

            // Whatever exception occures after this try,
            // will release concurrency lock.
            try {

                // Get order xmls.
                $ftpstream = $this->get_ftp_stream($config);
                $this->get_remote_xmls($ftpstream);

                // Check for new files
                $new_files = $this->get_order_filenames();

                if (!count($new_files)) {

                    $this->msglog(self::RS_OPENTRANS_EXIT_NONEWFILES, 2);
                    $this->concurrency_lock_release();
                    return self::RS_OPENTRANS_EXIT_NONEWFILES;

                }

                // Iterate through new files
                foreach ($new_files AS $filename) {

                    try {
                        $this->process_xml_file($filename, $config);
                        $this->archive_xml_filename($ftpstream, $filename);
                    } catch (rs_opentrans_exception $e) {
                        $this->msglog($e->getMessage(), 3);
                    } catch (Exception $e) {
                        $this->msglog('Exception in file ' . $e->getFile() . '@' . $e->getLine() . ': ' . PHP_EOL . $e->getMessage(), 3);
                        var_dump($e->getTraceAsString());
                    }

                }

            } catch (Exception $e) {

                $this->msglog($e, 3);
                $this->concurrency_lock_release();
                $this->msglog(self::RS_OPENTRANS_EXIT_ERROR, 3);
                return self::RS_OPENTRANS_EXIT_ERROR;

            }

            $this->concurrency_lock_release();
            $this->msglog(self::RS_OPENTRANS_EXIT_OK, 2);
            return self::RS_OPENTRANS_EXIT_OK;

      }

        /**
         * MAIN ENTRY POINT FOR SHOP-SPECIFIC PROCESSING OF XML-FILES
         *
         * @param string $filename
         */
        protected function process_xml_file($filename, $config) {

            $this->msglog('processing ' . basename($filename), 1);

            // Create opentrans object from xml
            $opentrans_order_reader = new rs_opentrans_document_reader_order_standard_2_1('xml', $filename);
            $opentrans_order = $opentrans_order_reader->get_document_data_order($filename);

            // Check opentrans object creation
            if (!is_a($opentrans_order,'rs_opentrans_document_order')) {
                throw new rs_opentrans_exception('failed to load rs_opentrans_document_order');
            }

            // frequently used vars from xml structure:
            // $opentrans_order
            $itemlist = $opentrans_order->get_item_list();
            $summary = $opentrans_order->get_summary();
            $header = $opentrans_order->get_header();
            $sourcinginfo = $header->get_sourcinginfo();
            $controlinfo = $header->get_controlinfo();
            $orderinfo = $header->get_orderinfo();
            $parties = $orderinfo->get_parties();
            $orderinfo_remarks = $orderinfo->get_remarks();

            $this->check_ts_orderid_for_conflict($orderinfo->get_order_id(), $filename);

            // Items
            $items = $this->magento_get_items($itemlist);

            // Customer
            $customer = $this->magento_get_customer($parties);

            // Currency
            $currency = $orderinfo->get_currency();

            // Payment
            $payment_data = $this->magento_get_paymenttype($orderinfo);

            $params = array('items' => $items,
                            'customer' => $customer,
                            'currency' => $currency,
                            'shipping_fee' => $orderinfo_remarks['shipping_fee'],
                            'config' => $config,
                            'payment_data' => $payment_data,
                            'total' => $summary->get_total_amount(),
                            'orderinfo_remarks' => $orderinfo_remarks,
                            'ts_orderid' => $orderinfo->get_order_id(),

            );
            $this->magento_create_order($params);

        }

        /**
         * Get Payment Info.
         *
         * @param mixed $orderinfo
         */
        protected function magento_get_paymenttype($orderinfo) {

            // Check parameter type (no typehint used - that shopsystem can run un REALLY old server.)
            if (!is_a($orderinfo, 'rs_opentrans_document_header_orderinfo')) {
                throw new rs_opentrans_exception('$orderinfo must be type rs_opentrans_document_header_orderinfo');
            }

            // Choose payment type to use
            $remarks = $orderinfo->get_remarks();

            if (isset($remarks['payment_type'])) {
                // New style payment type getter. Handles paypal, requieres custom remark.
                $payment_type = $remarks['payment_type'];
            } else {
                // Old school payment type getter. Fails with ew payment types like paypal.
                $payment_type = $orderinfo->get_payment()->get_type();
            }

            $config = Mage::getStoreConfig('OrderImport_options/shipping');

            // Translate opentrans-style payment type into shop-style.
            // Make aliases for most common types.

            switch($payment_type) {

                case 'cashondelivery':
                case 'cash':
                case 'cod':
                    $payment_data['method'] = 'cashondelivery';
                    break;

                case 'cc':
                case 'card':
                case 'creditcard':
                case 'creditcard_testsieger':

                    $payment_data['method'] = 'ccsave';
                    $payment_data['cc_type'] = 'OT'; // Other
                    $payment_data['cc_owner'] = $orderinfo->get_payment()->get_payment_specific_data_element('CARD_HOLDER_NAME');
                    $payment_data['cc_number'] = $orderinfo->get_payment()->get_payment_specific_data_element('CARD_NUM');
                    $payment_data['cc_number'] = preg_replace('/[^0-9]/', '1', $payment_data['cc_number']);
                    $card_expiration_mm_slash_yyyy = $orderinfo->get_payment()->get_payment_specific_data_element('CARD_EXPIRATION_DATE');
                    $payment_data['cc_exp_month'] = substr($card_expiration_mm_slash_yyyy,0,2);
                    $payment_data['cc_exp_year'] = substr($card_expiration_mm_slash_yyyy,3,4);

                    break;

                case 'paypal':
                    $payment_data['method'] = 'paypal_standard';
                    break;

                case 'testsieger':
                    if (isset($config['orderimport_ts_classmethodid'])
                        && $config['orderimport_ts_classmethodid']) {
                        $payment_data['method'] = $config['orderimport_ts_classmethodid'];
                    } else {
                        throw new rs_opentrans_exception('no testsieger.de payment type defined');
                    }
                    break;

                case 'ueberweisung':
                     $payment_data['method'] = 'banktransfer';

                // Default to payment tpe as specified, but lowercased.
                // Will handle new payment types like PayPal.
                // As shop system shall not recalculate order anyway,
                // we do not require it to recognise the used payment method.

                default:
                    $payment_data['method'] = 'cashondelivery'; #strtolower($remarks['payment_type']);
                    break;

            }

            if (isset($config['orderimport_payment_classmethodid'])
                && $config['orderimport_payment_classmethodid'] && $payment_type != 'testsieger') {

                 $this->msglog('Changing payment method to ' . $config['orderimport_payment_classmethodid'] . ' to work with magento 1.6');
                 $payment_data['method'] = $config['orderimport_payment_classmethodid'];

            }

            return $payment_data;

        }

        /**
        * Downloads new xml files
        *
        * @returns bool found_new
        */
        protected function get_remote_xmls($ftpstream) {

            $server_path = '/outbound';
            $remote_filelist = ftp_nlist( $ftpstream , $server_path );
            $found_new = false;

            // Check for folders and writability
            if (!file_exists($this->get_xml_inbound_path() . 'archive/')) {
                mkdir($this->get_xml_inbound_path() . 'archive/', 0777, true);
            }

            if(!is_writable($this->get_xml_inbound_path() . 'archive/')) {
                chmod($this->get_xml_inbound_path() . 'archive/', 0777);
            }

            if(!is_writable($this->get_xml_inbound_path())) {
                chmod($this->get_xml_inbound_path(), 0777);
            }

            if(!is_writable($this->get_xmlpath())) {
                chmod($this->get_xml_inbound_path(), 0777);
            }

            // load remote files
            foreach($remote_filelist AS $filename_with_path){

                //echo "scanning remote file $filename_with_path\n\n";
                if (false ===strpos($filename_with_path,'-ORDER.xml')) {
                    // $this->msglog("Skipping $filename_with_path");
                    continue;
                }

                // Check for duplicate
                if (in_array(basename($filename_with_path), $this->get_order_filenames(true))) {
                    $this->msglog("Skipping download of already downloaded $filename_with_path");
                    continue;
                }

                if (in_array(basename($filename_with_path).'.xml', $this->get_archived_filenames(true))) {
                    $this->msglog("Skipping download of already archived $filename_with_path");
                    continue;
                }

                //download
                $local_file = $this->get_xml_inbound_path() . basename($filename_with_path);
                $this->msglog("Saving to local file " . $local_file);
                $success = ftp_get($ftpstream, $local_file, $filename_with_path, FTP_BINARY);

                if ($success) {

                    $this->msglog("Got new xml $filename_with_path",2);
                    $found_new = true;

                } else {

                    $this->msglog("Could not get remote file " . $local_file);

                }
            }

            return $found_new;

        }

        /**
         * @returns string Path of xml inbound folder
         */
        protected function get_xml_inbound_path() {
            return $this->get_xmlpath().'inbound/';
        }

        /**
         * @returns string Path of xml folder
         */
        protected function get_xmlpath() {
            return $this->get_datapath().'xml/';
        }

        /**
         * @returns string Path of Data folder
         */
        protected function get_datapath() {
            return dirname(__FILE__).'/../Data/';
        }

        /*
        #########################################################################################
        #########
        #########    Helper Functions: Logging, Concurrency locking, arthimetrics, FTP
        #########
        #########################################################################################
         */

        /**
         * Checks if given TS order id has already been enetered into order mapping table.
         * If so, it @throws rs_opentrans_exception
         *
         * @param string $ts_orderid
         */
        protected function check_ts_orderid_for_conflict($ts_orderid, $filename) {

            $db = Mage::getSingleton('core/resource')->getConnection('core_write');

            $res = $db->fetchRow('SELECT count(*) AS cnt FROM ' . Mage::getConfig()->getTablePrefix() . 'sales_flat_order WHERE customer_note = ?', array($ts_orderid));

            if ($res['cnt'] > 0) {
                $this->msglog("Order with testsieger-order-id {$ts_orderid} found in mapping table.", 3);

                $inbound_file = $filename;
                $archive_file = $this->get_xml_inbound_path() . 'archive/' . basename($filename);

                // Order exists in archive
                if (file_exists($archive_file)) {

                    // Same file in inbound folder as in archive folder
                    if (md5_file($archive_file) == md5_file($inbound_file)) {

                        // Delete inbound order, move remote order, order has already been imported
                        unlink($inbound_file);
                        $this->archive_xml_filename_remotly($this->get_ftp_stream(), basename($filename));
                        throw new rs_opentrans_exception('Duplicate order with testsieger-order-id "' . $ts_orderid . '". Order "' . basename($filename) . '" will be deleted automatically');

                    } else {
                        throw new rs_opentrans_exception('Different orders with the same testsieger-order-id (' . $ts_orderid . ') - please check the order "' . basename($filename) . '" manually');
                    }

                } else {

                    // Order has been imported but its not in the archive folder, so we move it there
                    $this->archive_xml_filename_locally(basename($filename));
                    throw new rs_opentrans_exception('Moved order "' . basename($filename) . '" to the archive.');

                }
            }

        }

        /**
         * Searches xml folder for files to process.
         * @returns array of xml filenames to be processed.
         */
        protected function get_order_filenames($basename_only = false) {

            $filelist = GLOB( $this->get_xml_inbound_path() . '*-ORDER.xml' );

            if ($basename_only) {

                foreach ($filelist AS $k => $v) {
                    $filelist[$k] = basename($v);
                }

            }

            return $filelist;

        }

        /**
         * Searches xml archive folder for already processed files.
         * @returns array of archived xml filenames.
         */
        protected function get_archived_filenames($basename_only) {

            $filelist = GLOB( $this->get_xml_inbound_path() . 'archive/*-ORDER.xml' );

            if ($basename_only) {

                foreach ($filelist AS $k => $v) {
                    $filelist[$k] = basename($v);
                }

            }

            return $filelist;
        }

        /**
         * Check if we have a concurrent lock.
         * Ignore Locks older than an hour.
         * (We also have orderwise monitoring in place)
         *
         * @throws rs_opentrans_exception('Exiting due to concurrency lock [...]');
         */
        protected function concurrency_lock_check() {

            // No lockfile - no lock
            if (!file_exists($this->concurrency_lock_get_filename())) {
                return 'no_lock';
            }

            // We got lockfile. Open and check if it might be outdated
            // due to failure to remove it.
            $fh = $this->concurrency_lock_get_filehandle('r');
            $timestamp = 0;

            if ($fh) {
                $timestamp = fread($fh, 128);
            }

            // Current time is 600 seconds. Smaller than before -> 1 hour
            if (($timestamp + self::LOCK_TIME) > time()) {
                die('Exiting due to concurrency lock, beeing ' . (time()-$timestamp) . ' seconds old.  Lock will be deleted after ' . self::LOCK_TIME . ' seconds. / Beende auf Grund der ' . (time()-$timestamp) . ' alten Konkurenzsperre. Sperre wird nach ' . self::LOCK_TIME . ' Sekunden gel&ouml;scht.');
            }

            // Lockfile is outdated.
            $this->msglog('Removing outdated lockfile.',3);
            $this->concurrency_lock_release();
            return 'outdated';

        }

        /**
         * Set lock to prevent concurrent execution.
         *
         * @throws rs_opentrans_exception('Unable to establish concurrency lock file.');
         */
        protected function concurrency_lock_set() {

            $fh = $this->concurrency_lock_get_filehandle('w+');

            if (!$fh) {
                $this->msglog('Unable to establish concurrency lock file.');
                throw new rs_opentrans_exception('Unable to establish concurrency lock file.');
            }

            $this->msglog('Locked', 0);

            fwrite($fh,time());
            fclose($fh);
            return true;

        }

        /**
         * Release concurrency lock
         */
        protected function concurrency_lock_release() {
            $this->msglog('Unlocked', 0);
            @unlink($this->concurrency_lock_get_filename());
        }

        /**
         * @returns string Filepath and -name of Lockfile
         */
        protected function concurrency_lock_get_filename() {
            return $this->get_datapath() . '/testsieger_lockfile.txt';
        }

        /**
         * Get handle of Lockfile
         *
         * @param string $mode of fopen like 'w+' or 'r'
         * @return resource Filehandle
         */
        protected function concurrency_lock_get_filehandle($mode) {
            return fopen($this->concurrency_lock_get_filename(), $mode);
        }

        /**
        * Connects remote FTP, returns stream handle.
        * Will return cached Stream once connected.
        *
        * @return resource $this->_ftp_stream
        */
        protected function get_ftp_stream(array $config) {

            if (isset($this->_ftp_stream)){
                return $this->_ftp_stream;
            }

            //Connect to the FTP server
            $ftpstream = @ftp_connect($config['orderimport_access_ftphost'], $config['orderimport_access_ftpport']);
            if (!$ftpstream ) {throw new Exception('failed ftp connection');}

            //Login to the FTP server
            $login = @ftp_login($ftpstream, $config['orderimport_access_ftpuser'], $config['orderimport_access_ftppass']);
            if (!$login) {throw new Exception('failed ftp login');}

            //We are now connected to FTP server.

            // turn on passive mode transfers
            ftp_pasv ($ftpstream, true) ;

            $this->_ftp_stream = $ftpstream;

            return $this->_ftp_stream;

        }

        /**
        * Moves xml file to archive folders.
        *
        * @param stream $ftpstream
        * @param string $filename
        */
        public function archive_xml_filename($ftpstream, $filename) {

            $filename = basename($filename);

            $this->archive_xml_filename_remotly($ftpstream, $filename);
            $this->archive_xml_filename_locally($filename);

        }

        /**
        * Moves xml file remotly from /outbound to /backup
        *
        * @param stream $ftpstream
        * @param string $filename
        */
        protected function archive_xml_filename_remotly($ftpstream, $filename) {

            //remote archive
            $success = ftp_rename( $ftpstream,
                        "/outbound/$filename" ,
                        "/backup/$filename"
                        );

            if ($success) {
                $this->msglog("Remotely archived $filename");
            } else {
                $this->msglog("Could not remotely archive $filename",3);
            }

        }

        /**
        * Moves xml file locally to archive folder
        *
        * @param stream $ftpstream
        * @param string $filename
        */
        protected function  archive_xml_filename_locally($filename) {

            $success = copy( $this->get_xml_inbound_path() . $filename, $this->get_xml_inbound_path() . 'archive/' . $filename);

            if ($success) {
                $success = unlink($this->get_xml_inbound_path() . $filename);
            }

            if ($success) {
                $this->msglog("Locally archived $filename");
            } else {
                $this->msglog("Could not locally archive $filename",3);
            }

        }

        /**
         * Logger.
         *
         * @param mixed $msg
         * @param mixed $lvl 0: Minor Notice. 1: Notice. 2:Logged notice. 3: Logged error.
         */
        public function msglog($msg, $lvl = 0) {

            if (!is_string($msg)) {
                $msg = serialize($msg);
            }

            $msg = htmlspecialchars($msg);

            $out = date('Y.m.d H:i:s: ') . $msg;
            $log_to_file = false;

            if (0 == $lvl) {
                //minor notice
                $out = "$msg<br>";
            } else if (1 == $lvl) {
                //notice
                $out = "<b>$msg</b><br>";
            } else if (2 == $lvl) {

                //logged notice
                $out = "<b><u>$msg</u></b><br>";
                $log_to_file = true;

            } else if (3 == $lvl) {

                //logged error

                $out = "<span style='color:#F00'><b><u>$msg</u></b></span><br><pre></pre>";
                $log_to_file = true;
            }

            echo $out;

            if ($log_to_file) {
                file_put_contents($this->get_datapath() .'/testsieger_logfile.html', $out,  FILE_APPEND | LOCK_EX);
            }

        }

        /*
        #########################################################################################
        #########################################################################################
        #########################################################################################
        #########################################################################################
        #########################################################################################
        #########################################################################################
        #########################################################################################
        #########################################################################################
        */

        /**
         * Iterate throu itemlist and create array of order objects.
         *
         * @param mixed $itemlist
         * @return array Offer item object list.
         */
        protected function magento_get_items($itemlist) {

            $items = array();

            foreach ($itemlist AS $key => $item) {

                $remarks = $item->get_remarks();

                echo "<pre>";

                $product = Mage::getModel('catalog/product');

                $bruttoprice = $item->get_product_price_fix()->get_price_amount();
                $nettoprice = $bruttoprice / (1 + $item->get_tax_details_fix()->get_tax());
                $product->setPrice($nettoprice);
                $product->setFinalPrice($bruttoprice);
                $product->setSku($item->get_product_id()->get_supplier_pid());
                $product->setName($remarks['product_name']);
                $product->setType_id('simple');

                /*
                //Commented out, since tax id can be hard set to 1 = default.
                // Get Tax Class ID
                $tax_rate = 100.0 * $item->get_tax_details_fix()->get_tax();

                $db = Mage::getSingleton('core/resource')->getConnection('core_write');
                $res = $db->raw_fetchRow('SELECT ' . Mage::getConfig()->getTablePrefix() . 'tax_calculation_rate_id
                                        FROM tax_calculation_rate
                                        WHERE rate=' . $tax_rate);
                $tax_class_id = (int)$res['tax_calculation_rate_id'];

                // Check if Tax Class ID found
                if (!$tax_class_id) {
                    throw new Exception(
                        'You must create a tax class with a value of ' . $tax_rate . ' percent. '
                        . 'Erstellen Sie eine MwSt.-Klasse mit ' . $tax_rate . ' Prozent. '
                    );
                }
                //Set Tax Class ID
                */

                $product->setTax_class_id(1);

                $amount = array('qty' => $item->get_quantity());

                $items[] = array('product' => $product,
                                    'amount' => $amount,
                                    'truebrutto' => $bruttoprice,
                                    'taxrate' => $item->get_tax_details_fix()->get_tax());

            }

            return $items;

        }

        /**
         * Iterate throu parties, build customer data,
         * i.e. email, billing address and delivery address.
         *
         * @param array $parties
         * @returns array $customer
         */
        protected function magento_get_customer($parties) {

            // Type check

            if (!is_array($parties)) {
                throw new rs_opentrans_exception('$parties must be array');
            }

            $db = Mage::getSingleton('core/resource')->getConnection('core_write');
            $customer = array();

            // Rename party keys into their specific function

            foreach ($parties AS $key => $party) {

                if (!is_a($party, 'rs_opentrans_document_party')) {
                    throw new rs_opentrans_exception('$parties must be type rs_opentrans_document_party');
                }

                $parties[$party->get_role()] = $party;
                unset($parties[$key]);

            }

            // Iterate shipping and billing address

            foreach ( array('invoice' => 'billingaddress',
                            'delivery' => 'shippingaddress')
                        AS $partyname => $addresstype) {

                $current_address = $parties[$partyname]->get_address();

                // Get country code

                switch (strtolower($current_address->get_country())) {

                    case 'österreich':
                    case 'oesterreich':
                    case 'osterreich':
                    case 'austria':
                        $country_id = 'AT';
                        break;

                    case 'schweiz':
                    case 'switzerland':
                        $country_id = 'CH';
                        break;

                    case 'deutschland':
                    case 'germany':
                    default:
                        $country_id = 'DE';
                        break;
                }

                // Get state / region / canton

                $state_name = $current_address->get_state();
                $region_id = $this->magento_get_region_id($country_id, $state_name);

                // Get Address

                $customer[$addresstype] = array(
                    'firstname' => $current_address->get_name2(),
                    'lastname' => $current_address->get_name3(),
                    'street' => $current_address->get_street(),
                    'city' => $current_address->get_city(),
                    'postcode' => $current_address->get_zip(),
                    'telephone'=> 0123456789, // Set below
                    'country_id' => 'DE',
                    'company' => $current_address->get_name(),
                    'region_id' => $region_id, // id from directory_country_region table
                );

                // Get first found phone number
                foreach ($current_address->get_phone() AS $number) {

                    if ($number > 0) {
                        $customer[$addresstype]['telephone'] = $number;
                        break;
                    }

                }

                // Get first found mail address

                foreach ($current_address->get_emails() AS $mail) {

                    if ($number > 0) {
                        $customer['email'] = $mail;
                        break;
                    }

                }

            }

            return $customer;

        }

        /**
         * Returns blanco region for given country.
         * Creates blanco region if needed.
         *
         * @param string $country_id Iso-Alpha-2
         * @return int Region ID
         */
        protected function magento_get_region_id($country_id, $state_name = '') {

            $db = Mage::getSingleton('core/resource')->getConnection('core_write');

            // Try to get region id. state name is either from XML or NULL
            $sql_get_region_id = 'SELECT *
                                    FROM ' . Mage::getConfig()->getTablePrefix() . 'directory_country_region
                                    WHERE country_id="' . $country_id . '"
                                    AND code="TESTSIEGER' . $state_name . '" ';

            $sql_get_region_id .= ($state_name ?
                                    ' AND default_name = "' . $state_name . '"'
                                    : ' AND default_name IS NULL');

            $sql_get_region_id .= ' LIMIT 1';

            $res = $db->raw_fetchRow($sql_get_region_id);

            // If Region ID not found, create and refetch.
            if (!$res['region_id']){

                $db->query('INSERT INTO '.Mage::getConfig()->getTablePrefix().'directory_country_region
                            (country_id, code, default_name)
                            VALUES (?, ?, ?)',
                            array($country_id,
                                    'TESTSIEGER' . $state_name,
                                    ($state_name?$state_name:NULL)
                            )
                );

                $res = $db->raw_fetchRow($sql_get_region_id);

            }

            return (int)$res['region_id'];

        }

        /**
        * Takes the results of all procesisng (items, address etc.)
        * and finally creates + saves order.
        *
        * @param array $params Associative Array of all parameters
        */
        protected function magento_create_order(array $params) {

            extract($params);

            // Create Quote (Magentos wording for shopping basket)

            require_once 'app/Mage.php';

            Mage::app();

            /**
            * @var Mage_Sales_Model_Quote
            */
            $quote = Mage::getModel('sales/quote')
                ->setStoreId(Mage::app()->getStore(true)->getId());

            // Currency

            $quote->setBaseCurrencyCode($currency);

            // Add Items to Quote

            foreach ($items AS $item) {
                $quote->addProduct($item['product'], new Varien_Object($item['amount']));
            }

            // Add Customer

            $quote->setCustomerEmail($customer['email']);
            $billingAddress = $quote->getBillingAddress()->addData($customer['billingaddress']);
            $shippingAddress = $quote->getShippingAddress()->addData($customer['shippingaddress']);
            $quote->setCustomerFirstname($customer['billingaddress']['firstname']);
            $quote->setCustomerLastname($customer['billingaddress']['lastname']);

            $quote->setCustomerNote($ts_orderid);
            $quote->setCustomerIsGuest(true);

            // Add Shipping

            if (!$shipping_fee) {// free shipping?
                $shipping = 'freeshipping_freeshipping';
            } else if ($config['orderimport_shipping_classmethodid']) {
                $shipping = $config['orderimport_shipping_classmethodid'];
            } else {
                $shipping = 'flatrate_flatrate';
            }


            $shippingAddress->setCollectShippingRates(true)->collectShippingRates()
                    ->setShippingMethod($shipping) //Class_Method, E.g. 'flatrate_flatrate','freeshipping_freeshipping', 'tablerate_bestway'
                    ->setPaymentMethod($payment_data['payment_method']);

            // Combine shipping and payment fee
            $shipping_and_payment_fee = (isset($orderinfo_remarks['additional_costs'])
                                            ? (float)$orderinfo_remarks['additional_costs']
                                            : 0)
                                         + (isset($orderinfo_remarks['services_1_man'])
                                            ? (float)$orderinfo_remarks['services_1_man']
                                            : 0)
                                         + (isset($orderinfo_remarks['services_2_man'])
                                            ? (float)$orderinfo_remarks['services_2_man']
                                            : 0)
                                        + (float)$shipping_fee;

            // Add Payment
            try {
                $quote->getPayment()->importData($payment_data);
            } catch (Exception $e) {
                throw new rs_opentrans_exception($e->getMessage());
            }

            // Calc and save
            $quote->collectTotals()->save();
            $service = Mage::getModel('sales/service_quote', $quote);
            $service->submitAll();
            $order = $service->getOrder();

            printf("Created order %s\n", $order->getIncrementId());

            // Enforce corrections

            $shipping_descr = (isset($orderinfo_remarks['delivery_method'])
                                            ? $orderinfo_remarks['delivery_method']
                                            : '')
                                . (isset($orderinfo_remarks['additional_costs'])
                                            ? ', Gebühren'
                                            : '')
                                . (isset($orderinfo_remarks['services_1_man'])
                                            ? ', Service 1 Person'
                                            : '')
                                . (isset($orderinfo_remarks['services_2_man'])
                                            ? ', Service 2 Personen'
                                            : '');

            $order->setShippingDescription($shipping_descr);
            $order->save();

            $db = Mage::getSingleton('core/resource')->getConnection('core_write');
            $db->query('UPDATE ' . Mage::getConfig()->getTablePrefix() . 'sales_flat_order
                            SET base_grand_total = ?,
                            base_shipping_amount = ?,
                            grand_total = ?,
                            shipping_amount = ?,
                            base_shipping_incl_tax = ?,
                            shipping_incl_tax = ?
                        WHERE entity_id = ?
                            ',
                            array($total,
                                  $shipping_and_payment_fee,
                                  $total,
                                  $shipping_and_payment_fee,
                                  $shipping_and_payment_fee,
                                  $shipping_and_payment_fee,
                                  $order->getId()
                            )
                );

            foreach ($items AS $item) {

                $brut = (float)$item['truebrutto'];
                $tax = $brut - (float)$item['product']->getPrice();
                $net = $brut - $tax;
                $amnt = (float)$item['amount']['qty'];

                $db->query('UPDATE ' . Mage::getConfig()->getTablePrefix() . 'sales_flat_order_item
                            SET
                                price = "' . $net . '",
                                base_price = "' . $net . '", -- netto 1
                                original_price = "' . $brut . '", -- brutto 1
                                base_original_price = "' . $brut . '", -- brutto 1

                                tax_amount = "' . ($tax * $amnt) . '", -- tax all
                                base_tax_amount = "' . ($tax * $amnt) . '", -- tax all

                                row_total = "' . ($net * $amnt) . '", -- net all
                                base_row_total = "' . ($net * $amnt) . '", -- net all

                                price_incl_tax = "' . $brut . '", -- brutto 1
                                base_price_incl_tax = "' . $brut . '", -- brutto 1

                                row_total_incl_tax = "' . ($brut * $amnt) . '", -- brut all
                                base_row_total_incl_tax = "' . ($brut * $amnt) . '" -- brut all

                            WHERE
                                order_id = "' . $order->getId() . '"
                                AND sku = "' . $item['product']->getSku() .'"
                                '
                    );

            }

            $order->sendNewOrderEmail();

        }

    }