<?php

set_time_limit(0);
/**
 * Class representing the Brevo API
 */
class BrevoApi {
    private BrevoApiConfig $config;

    /**
     * Initialize the Brevo API configuration
     *
     * @throws Exception
     */
    public function __construct()
    {
        //debugVar( ini_get('safe_mode') );
        $mandant = DbhCompany::begin();
        $config = $mandant->fetchKeyValue("SELECT key, value FROM defaults_oserp WHERE key IN ('brevo_api_endpoint', 'brevo_api_key', 'brevo_api_test_enabled', 'brevo_api_test_recipient')");

        if (empty($config['brevo_api_key'])) {
            throw new Exception('Brevo API key is not configured');
        }

        $this->config = new BrevoApiConfig();
        $this->config->apiKey = $config['brevo_api_key'];
        $this->config->apiEndpoint = $config['brevo_api_endpoint'] ?? 'https://api.brevo.com/v3';
        $this->config->apiTestEnabled = ($config['brevo_api_test_enabled'] ?? '') === 't';
        $this->config->apiTestRecipient = $config['brevo_api_test_recipient'] ?? null;
    }

    /**
     * Gets the account plan quota
     * https://developers.brevo.com/reference/getaccount
     *
     * @return int remaining credits
     * @throws Exception
     */
    public function checkAccountPlanQuota(): int
    {
        $response = $this->request('GET', '/account');

        $plans = $response['plan'] ?? [];
        foreach ($plans as $plan) {
            if ($plan['type'] === 'free' || $plan['type'] === 'payAsYouGo' || $plan['type'] === 'subscription') {
                return $plan['credits'] ?? 0;
            }
        }

        return 0;
    }

    /**
     * Sends a transactional email using Brevo
     * https://developers.brevo.com/reference/sendtransacemail
     *
     * @param BrevoEmail $email
     * @return array response data
     * @throws Exception
     */
    public function sendTransactional(BrevoTransactionalEmail $email): array
    {
        // before sending, check if contact already exists at brevo and create if not
        // this is required to get the unsubscribe link and handling at brevo working properly
        try {
            $contact = $this->getContact($email->recipientEmail);
            if ($contact === false) {
                $this->createContact($email->recipientEmail, $email->recipientName);
            }
        } catch (Exception $e) {
            throw new Exception('Failed to ensure contact exists at Brevo: '.$e->getMessage());
        }

        $data = [
            'to' => [
                [
                    'email' => $this->config->apiTestEnabled ? $this->config->apiTestRecipient : $email->recipientEmail,
                    'name' => $email->recipientName,
                ]
            ],
            'templateId' => $email->templateId,
        ];

        if (!empty($email->senderName) && !empty($email->senderEmail)) {
            $data['sender'] = [
                'name' => $email->senderName,
                'email' => $email->senderEmail,
            ];
            $data['replyTo'] = (!empty($email->replyToEmail) && $email->replyToEmail !== "[DEFAULT_REPLY_TO]") ?  $email->replyToEmail : $email->senderEmail;
        }

        // submitting empty params will cause Brevo to reject the request (HTTP 400)
        if (!empty($email->params)) {
            $data['params'] = $email->params;
        }

        return $this->request('POST', '/smtp/email', $data);
    }

    /**
     * Gets a contact by email
     * https://developers.brevo.com/reference/getcontact
     *
     * @param string $email
     * @return array|false contact data or false if not found
     * @throws Exception
     */
    public function getContact(string $email): array|false
    {
        try {
            $response = $this->request('GET', '/contacts/'.urlencode($email));
        } catch (Exception $e) {
            if ($e->getCode() === 404) {
                return false; // Contact not found
            }
            throw $e;
        }

        return $response;
    }

    /**
     * Creates a new contact
     * https://developers.brevo.com/reference/createcontact
     *
     * @param string $email
     * @param string $name
     * @return bool success
     * @throws Exception
     */
    public function createContact(string $email, string $name): bool
    {
        $data = [
            'email' => $email,
            'attributes' => [
                'LASTNAME' => $name,
            ],
            'updateEnabled' => false  // do not update/override if contact exists
        ];

        try {
            $response = $this->request('POST', '/contacts', $data);
            if (isset($response['id'])) {
                return true;
            }

            return false;
        } catch (Exception $e) {
            if ($e->getCode() === 400 || $e->getCode() === 425) {
                // Contact already exists
                return false;
            }

            throw $e;
        }
    }

    /**
     * Lists all active email templates
     * https://developers.brevo.com/reference/getsmtptemplates
     *
     * @return array list of templates
     * @throws Exception
     */
    public function listTemplates(): array
    {
        $response = $this->request('GET', '/smtp/templates?templateStatus=true');
        if (isset($response['count']) && $response['count'] > 0) {
            return $response['templates'];
        }

        return [];
    }

    /**
     * Makes a get/post request to the Brevo API
     *
     * @param string $method
     * @param string $endpoint
     * @param array|null $data
     * @return array response data
     * @throws Exception
     */
    private function request(string $method, string $endpoint, ?array $data = null): array
    {
        $method = strtoupper($method);
        if ($method !== 'GET' && $method !== 'POST') {
            throw new Exception("Unsupported HTTP method: {$method}");
        }

        $ch = curl_init($this->config->apiEndpoint.$endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, $method === 'POST' ? CURLOPT_POST : CURLOPT_HTTPGET, true);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'api-key: '.$this->config->apiKey,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode <= 299) {
            return json_decode($response, true);
        } else {
            throw new Exception("Brevo API request failed with status code {$httpCode}: {$response}", $httpCode);
        }
    }
}

/**
 * Class representing the Brevo API configuration
 */
class BrevoApiConfig {
    private array $data = [];

    private array $fillable = [
        'apiKey',
        'apiEndpoint',
        'apiTestEnabled',
        'apiTestRecipient',
    ];

    public function __get($property)
    {
        if (in_array($property, $this->fillable)) {
            return $this->data[$property] ?? null;
        }
        throw new Exception("Property {$property} does not exist");
    }

    public function __set($property, $value)
    {
        if (in_array($property, $this->fillable)) {
            $this->data[$property] = $value;
            return;
        }
        throw new Exception("Property {$property} does not exist");
    }
}

/**
 * Class representing the setup for a Brevo transactional email
 */
class BrevoTransactionalEmail {
    private array $data = [];

    private array $fillable = [
        'senderName',
        'senderEmail',
        'replyToEmail',
        'recipientName',
        'recipientEmail',
        'templateId',
        'params',
    ];

    public function __get($property)
    {
        if (in_array($property, $this->fillable)) {
            return $this->data[$property] ?? null;
        }
        throw new Exception("Property {$property} does not exist");
    }

    public function __set($property, $value)
    {
        if (in_array($property, $this->fillable)) {
            $this->data[$property] = $value;
            return;
        }
        throw new Exception("Property {$property} does not exist");
    }
}