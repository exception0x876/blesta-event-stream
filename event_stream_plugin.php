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
        $this->Companies->setSetting($plugin->company_id, 'event_stream.public_key', '', true);
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
        $this->Companies->unsetSetting($plugin->company_id, 'event_stream.public_key');
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
     * @return array
     */
    protected function getClientInfo($client)
    {
        return [
            'id' => $client->id ?? '',
            'user_id' => $client->user_id ?? '',
            'status' => $client->status ?? '',
            'id_code' => $client->id_code ?? '',
            'contact_id' => $client->contact_id ?? '',
            'first_name' => $client->first_name ?? '',
            'last_name' => $client->last_name ?? '',
            'company' => $client->company ?? '',
            'title' => $client->title ?? '',
            'email' => $client->email ?? '',
            'address1' => $client->address1 ?? '',
            'address2' => $client->address2 ?? '',
            'city' => $client->city ?? '',
            'state' => $client->state ?? '',
            'zip' => $client->zip ?? '',
            'country' => $client->country ?? '',
            'username' => $client->username ?? '',
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
            $eventData = $this->getClientInfo($params['client']);
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
                $payload = (array) $transaction;
                if (!empty($payload['client_id'])) {
                    Loader::loadModels($this, ['Clients']);
                    $client = $this->Clients->get($params['client_id']);
                    if (!empty($client)) {
                        $payload['client'] = $this->getClientInfo($client);
                    }
                }
                $this->sendEvent('transactionAdded', $payload);
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
                $headers[] = 'X-Event-Stream-Signature: ' . base64_encode($signature);
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
