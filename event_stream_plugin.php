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
            $eventData = [
                'id' => $params['client']->id ?? '',
                'user_id' => $params['client']->user_id ?? '',
                'status' => $params['client']->status ?? '',
                'id_code' => $params['client']->id_code ?? '',
                'contact_id' => $params['client']->contact_id ?? '',
                'first_name' => $params['client']->first_name ?? '',
                'last_name' => $params['client']->last_name ?? '',
                'company' => $params['client']->company ?? '',
                'title' => $params['client']->title ?? '',
                'email' => $params['client']->email ?? '',
                'address1' => $params['client']->address1 ?? '',
                'address2' => $params['client']->address2 ?? '',
                'city' => $params['client']->city ?? '',
                'state' => $params['client']->state ?? '',
                'zip' => $params['client']->zip ?? '',
                'country' => $params['client']->country ?? '',
                'username' => $params['client']->username ?? '',
            ];
            $this->sendEvent('clientAdded', $eventData);
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
        $params = $event->getParams();
        if (!empty($params['invoice_id'])) {
            Loader::loadModels($this, ['Invoices']);
            $invoice = $this->Invoices->get($params['invoice_id']);

            if ($invoice) {
                $this->sendEvent('invoiceClosed', (array) $invoice);
            }
        }
    }

    /**
     * @param stdClass $event
     * @return void
     */
    public function sendTransaction($event)
    {
        $params = $event->getParams();
        if (!empty($params['transaction_id'])) {
            Loader::loadModels($this, ['Transactions']);
            $transaction = $this->Transactions->get($params['transaction_id']);

            if ($transaction) {
                $this->sendEvent('transactionAdded', (array) $transaction);
            }
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
        Loader::loadModels($this, ['Companies']);
        $endpoint = $this->Companies->getSetting($company_id, 'event_stream.endpoint');
        if (empty($endpoint->value)) {
            return;
        }

        $post_data = json_encode([
            'event' => $event,
            'payload' => $payload
        ]);

        $curl = curl_init();
        $headers = ['Content-Type: application/json'];

        $private_key = $this->Companies->getSetting($company_id, 'event_stream.private_key');
        if (!empty($private_key->value)) {
            $sign_result = openssl_sign($post_data, $signature, $private_key->value, OPENSSL_ALGO_SHA256);
            if ($sign_result && !empty($signature)) {
                $headers[] = 'X-Event-Stream-Signature: ' . $signature;
            }
        }

        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_URL, $endpoint->value);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($curl, CURLOPT_SSLVERSION, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        curl_exec($curl);
        curl_close($curl);
    }
}
