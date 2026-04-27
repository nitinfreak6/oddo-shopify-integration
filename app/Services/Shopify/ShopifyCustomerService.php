<?php

namespace App\Services\Shopify;

class ShopifyCustomerService
{
    public function __construct(private readonly ShopifyService $shopify) {}

    /**
     * Create a customer in Shopify.
     */
    public function create(array $customerData): array
    {
        $response = $this->shopify->post('customers.json', ['customer' => $customerData]);

        return $response['customer'];
    }

    /**
     * Update a customer in Shopify.
     */
    public function update(string $shopifyCustomerId, array $customerData): array
    {
        $response = $this->shopify->put("customers/{$shopifyCustomerId}.json", ['customer' => $customerData]);

        return $response['customer'];
    }

    /**
     * Search customers by email.
     */
    public function findByEmail(string $email): ?array
    {
        $results = $this->shopify->get('customers/search.json', ['query' => "email:{$email}"])['customers'] ?? [];

        return $results[0] ?? null;
    }

    /**
     * Build Shopify customer payload from Odoo partner data.
     */
    public function buildPayload(array $partner): array
    {
        $nameParts = explode(' ', $partner['name'], 2);

        $payload = [
            'first_name'        => $nameParts[0],
            'last_name'         => $nameParts[1] ?? '',
            'email'             => $partner['email'] ?? '',
            'phone'             => $partner['phone'] ?? $partner['mobile'] ?? '',
            'accepts_marketing' => !($partner['opt_out'] ?? false),
        ];

        if (!empty($partner['street'])) {
            $payload['addresses'] = [[
                'address1' => $partner['street'] ?? '',
                'address2' => $partner['street2'] ?? '',
                'city'     => $partner['city'] ?? '',
                'zip'      => $partner['zip'] ?? '',
                'country'  => is_array($partner['country_id']) ? $partner['country_id'][1] : '',
                'province' => is_array($partner['state_id']) ? $partner['state_id'][1] : '',
            ]];
        }

        return $payload;
    }
}
