<?php

class ClientMain extends AppController
{
    public function index()
    {
        $company_id = Configure::get('Blesta.company_id');
        Loader::loadModels($this, ['Companies']);
        $private_key = $this->Companies->getSetting($company_id, 'event_stream.private_key');
        $allow_origin = $this->Companies->getSetting($company_id, 'event_stream.allow_origin');
        $user_data = json_encode([
            'client_id' => $this->Session->read('blesta_client_id'),
            'staff_id' => $this->Session->read('blesta_staff_id'),
            'time' => time(),
        ]);

        $signature = '';
        if (!empty($private_key->value)) {
            $sign_result = openssl_sign($user_data, $raw_signature, $private_key->value, OPENSSL_ALGO_SHA256);
            if ($sign_result && !empty($raw_signature)) {
                $signature = base64_encode($raw_signature);
            }
        }

        // Top-level redirect rather than a fetch()able JSON response: the
        // Blesta session cookie is SameSite=Lax, so it's only present on a
        // real navigation like this one (Blesta's own client area linking
        // here), never on a cross-site fetch() from the consuming site
        // (confirmed empirically). allow_origin doubles as the redirect
        // destination's origin here.
        if (!empty($allow_origin->value) && $signature !== '') {
            $callback_url = rtrim($allow_origin->value, '/') . '/login/blesta/callback'
                . '?rawPayload=' . urlencode($user_data)
                . '&signature=' . urlencode($signature);
            header('Location: ' . $callback_url);
            exit();
        }

        // Fallback when allow_origin/signing aren't configured — same
        // JSON response as before, useful for manually checking the
        // session/signing setup itself.
        header('Content-Type: application/json');
        if ($signature !== '') {
            header('X-Event-Stream-Signature: ' . $signature);
        }
        echo($user_data);
        exit();
    }
}