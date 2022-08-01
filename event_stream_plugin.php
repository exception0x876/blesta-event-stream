<?php

/**
 * Event Stream plugin handler
 *
 * @package blesta
 * @subpackage blesta.plugins.event_stream
 */
class EventStreamPlugin extends Plugin
{
    /**
     * Init
     */
    public function __construct()
    {
        Language::loadLang('event_stream_plugin', null, dirname(__FILE__) . DS . 'language' . DS);

        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
    }

    /**
     * Performs any necessary bootstraping actions
     *
     * @param int $plugin_id The ID of the plugin being installed
     */
    public function install($plugin_id)
    {
        Loader::loadModels($this, ['Companies', 'PluginManager']);

        $plugin = $this->PluginManager->get($plugin_id);

        if (!$plugin) {
            return;
        }

        $this->Companies->setSetting($plugin->company_id, 'event_stream.endpoint', '', true);
    }

    /**
     * Performs any necessary cleanup actions
     *
     * @param int $plugin_id The ID of the plugin being uninstalled
     * @param bool $last_instance True if $plugin_id is the last instance
     *  across all companies for this plugin, false otherwise
     */
    public function uninstall($plugin_id, $last_instance)
    {
        Loader::loadModels($this, ['Companies', 'PluginManager']);

        $plugin = $this->PluginManager->get($plugin_id);

        if (!$plugin) {
            return;
        }

        $this->Companies->unsetSetting($plugin->company_id, 'event_stream.endpoint');
    }

    public function getEvents()
    {
        return [
            [
                'event' => 'Clients.create',
                'callback' => ['this', 'sendClientAdded']
            ],
            [
                'event' => 'Clients.edit',
                'callback' => ['this', 'sendClientUpdated']
            ],
            [
                'event' => 'Invoices.setClosed',
                'callback' => ['this', 'sendInvoiceClosed']
            ],
            [
                'event' => 'Transactions.add',
                'callback' => ['this', 'sendTransaction']
            ]
        ];
    }

    /**
     * @param stdClass $event
     * @return void
     */
    public function sendClientAdded($event)
    {
        $params = $event->getParams();
        if (!empty($params['client'])) {
            $this->sendEvent('clientAdded', $params['client']);
        }
    }

    /**
     * @param stdClass $event
     * @return void
     */
    public function sendClientUpdated($event)
    {
        $this->sendEvent('clientUpdated', $event->getParams());
    }

    /**
     * @param stdClass $event
     * @return void
     */
    public function sendInvoiceClosed($event)
    {
        $this->sendEvent('invoiceClosed', $event->getParams());
        //Loader::loadModels($this, ['Invoices']);
        //$invoice = $this->Invoices->get($invoice_id);

        /*if ($invoice) {
            $this->sendEvent('invoiceClosed', (array) $invoice);
        }*/
    }

    /**
     * @param stdClass $event
     * @return void
     */
    public function sendTransaction($event)
    {
        $this->sendEvent('transactionAdded', $event->getParams());
        /*Loader::loadModels($this, ['Transactions']);
        $transaction = $this->Transactions->get($transaction_id);

        if ($transaction) {
            $this->sendEvent('transactionAdded', (array) $transaction);
        }*/
    }

    /**
     * @param string $event
     * @param array $payload
     * @return void
     */
    protected function sendEvent($event, $payload = [])
    {
        $company_id = Configure::get('Blesta.company_id');
        Loader::loadModels($this, ['Companies']);
        $endpoint = $this->Companies->getSetting($company_id, 'event_stream.endpoint');
        if (empty($endpoint->value)) {
            return;
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
            'event' => $event,
            'payload' => $payload
        ]));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_URL, $endpoint->value);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($curl, CURLOPT_SSLVERSION, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        curl_exec($curl);
        curl_close($curl);
    }
}
