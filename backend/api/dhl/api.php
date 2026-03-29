<?php

/**
 * DHL Parcel DE Shipping API v2 Wrapper
 * https://developer.dhl.com/api-reference/parcel-de-shipping-post-parcel-germany-v2
 */
class DhlApi {
    private DhlApiConfig $config;

    /**
     * DHL API Konfiguration laden
     *
     * @throws Exception
     */
    public function __construct()
    {
        $db = DbhCompany::begin();
        $rows = $db->getAll("SELECT key, value FROM defaults_oserp WHERE key LIKE 'dhl_%'");
        $config = [];
        foreach ($rows as $row) {
            $config[$row['key']] = $row['value'];
        }

        if (empty($config['dhl_api_key'])) {
            throw new Exception('DHL API Key ist nicht konfiguriert');
        }
        if (empty($config['dhl_user']) || empty($config['dhl_password'])) {
            throw new Exception('DHL GKV-Zugangsdaten sind nicht konfiguriert');
        }

        $this->config = new DhlApiConfig();
        $this->config->apiKey = $config['dhl_api_key'];
        $this->config->user = $config['dhl_user'];
        $this->config->password = $config['dhl_password'];
        $this->config->billingNumber = $config['dhl_billing_number'] ?? '';
        $this->config->sandbox = ($config['dhl_sandbox'] ?? '') === 't';
        $this->config->labelFormat = $config['dhl_label_format'] ?? '910-300-600';
        $this->config->defaultProduct = $config['dhl_default_product'] ?? 'V01PAK';

        // Absenderdaten
        $this->config->shipperName = $config['dhl_shipper_name'] ?? '';
        $this->config->shipperStreet = $config['dhl_shipper_street'] ?? '';
        $this->config->shipperHouse = $config['dhl_shipper_house'] ?? '';
        $this->config->shipperZip = $config['dhl_shipper_zip'] ?? '';
        $this->config->shipperCity = $config['dhl_shipper_city'] ?? '';
        $this->config->shipperCountry = $config['dhl_shipper_country'] ?? 'DEU';
    }

    /**
     * Sendung erstellen und Label abrufen
     *
     * @param array $shipment Sendungsdaten
     * @return array Ergebnis mit shipmentNo und label (Base64-PDF)
     * @throws Exception
     */
    public function createShipment(array $shipment): array
    {
        $data = [
            'profile' => 'STANDARD_GRUPPENPROFIL',
            'shipments' => [
                [
                    'product' => $shipment['product'] ?? $this->config->defaultProduct,
                    'billingNumber' => $this->config->billingNumber,
                    'refNo' => $shipment['reference'] ?? '',
                    'shipper' => [
                        'name1' => $this->config->shipperName,
                        'addressStreet' => $this->config->shipperStreet,
                        'addressHouse' => $this->config->shipperHouse,
                        'postalCode' => $this->config->shipperZip,
                        'city' => $this->config->shipperCity,
                        'country' => $this->config->shipperCountry
                    ],
                    'consignee' => [
                        'name1' => $shipment['recipient_name'],
                        'addressStreet' => $shipment['recipient_street'],
                        'addressHouse' => $shipment['recipient_house'] ?? '',
                        'postalCode' => $shipment['recipient_zip'],
                        'city' => $shipment['recipient_city'],
                        'country' => $shipment['recipient_country'] ?? 'DEU'
                    ],
                    'details' => [
                        'weight' => [
                            'uom' => 'kg',
                            'value' => (float) ($shipment['weight'] ?? 1)
                        ]
                    ]
                ]
            ]
        ];

        // Optionale Paketmaße
        if (!empty($shipment['length']) && !empty($shipment['width']) && !empty($shipment['height'])) {
            $data['shipments'][0]['details']['dim'] = [
                'uom' => 'cm',
                'length' => (int) $shipment['length'],
                'width' => (int) $shipment['width'],
                'height' => (int) $shipment['height']
            ];
        }

        $response = $this->request('POST', '/orders?printFormat=' . urlencode($this->config->labelFormat), $data);

        if (!isset($response['items'][0])) {
            throw new Exception('Keine Sendung in der DHL-Antwort');
        }

        $item = $response['items'][0];

        if (isset($item['validationMessages'])) {
            foreach ($item['validationMessages'] as $msg) {
                if (($msg['validationState'] ?? '') === 'Error') {
                    throw new Exception('DHL-Fehler: ' . ($msg['validationMessage'] ?? 'Unbekannt'));
                }
            }
        }

        return [
            'shipment_no' => $item['shipmentNo'] ?? '',
            'label_b64' => $item['label']['b64'] ?? ''
        ];
    }

    /**
     * Sendung stornieren
     *
     * @param string $shipmentNo DHL Sendungsnummer
     * @return bool
     * @throws Exception
     */
    public function deleteShipment(string $shipmentNo): bool
    {
        $this->request('DELETE', '/orders?shipment=' . urlencode($shipmentNo));
        return true;
    }

    /**
     * HTTP-Request an die DHL API
     *
     * @param string $method GET, POST, DELETE
     * @param string $endpoint API-Pfad
     * @param array|null $data Request-Body
     * @return array Response
     * @throws Exception
     */
    private function request(string $method, string $endpoint, ?array $data = null): array
    {
        $baseUrl = $this->config->sandbox
            ? 'https://api-sandbox.dhl.com/parcel/de/shipping/v2'
            : 'https://api-eu.dhl.com/parcel/de/shipping/v2';

        $ch = curl_init($baseUrl . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($method === 'POST' && $data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'dhl-api-key: ' . $this->config->apiKey
        ]);

        // Basic Auth (GKV-Zugangsdaten)
        curl_setopt($ch, CURLOPT_USERPWD, $this->config->user . ':' . $this->config->password);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode <= 299) {
            return json_decode($response, true) ?? [];
        }

        $errorMsg = $response;
        $decoded = json_decode($response, true);
        if (isset($decoded['detail'])) {
            $errorMsg = $decoded['detail'];
        } elseif (isset($decoded['title'])) {
            $errorMsg = $decoded['title'];
        }

        throw new Exception("DHL API Fehler (HTTP {$httpCode}): {$errorMsg}", $httpCode);
    }
}

/**
 * DHL API Konfiguration
 */
class DhlApiConfig {
    private array $data = [];

    private array $fillable = [
        'apiKey', 'user', 'password', 'billingNumber',
        'sandbox', 'labelFormat', 'defaultProduct',
        'shipperName', 'shipperStreet', 'shipperHouse',
        'shipperZip', 'shipperCity', 'shipperCountry'
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
