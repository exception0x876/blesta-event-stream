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
     * @param stdClass $client
     * @return void
     */
    public function sendClientAdded($client)
    {
        $this->sendEvent('clientAdded', (array) $client);
    }

    /**
     * @param stdClass $client
     * @return void
     */
    public function sendClientUpdated($client)
    {
        $this->sendEvent('clientUpdated', (array) $client);
    }

    /**
     * @param int $invoice_id
     * @param stdClass $old_invoice
     * @return void
     */
    public function sendInvoiceClosed($invoice_id)
    {
        Loader::loadModels($this, ['Invoices']);
        $invoice = $this->Invoices->get($invoice_id);

        if ($invoice) {
            $this->sendEvent('invoiceClosed', (array) $invoice);
        }
    }

    public function sendTransaction($transaction_id)
    {
        Loader::loadModels($this, ['Transactions']);
        $transaction = $this->Transactions->get($transaction_id);

        if ($transaction) {
            $this->sendEvent('transactionAdded', (array) $transaction);
        }
    }

    /**
     * @param string $event
     * @param array $payload
     * @return void
     */
    protected function sendEvent($event, $payload = [])
    {
        $company_id = Configure::get('Blesta.company_id');
        $endpoint = $this->Companies->getSetting($company_id, 'event_stream.endpoint');
        if (empty($endpoint)) {
            return;
        }
        $client = new \GuzzleHttp\Client();
        try {
            $client->postAsync($endpoint, [
                GuzzleHttp\RequestOptions::JSON => [
                    'event' => $event,
                    'payload' => $payload
                ]
            ]);
        } catch (Throwable $e) {
            //ignore errors
        }
    }
}
