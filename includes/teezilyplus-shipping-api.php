<?php

class Teezilyplus_Shipping_API
{
    const TEEZILYPLUS_API_SHIPPING_RATES_URL = 'https://plus.teezily.com/api/woocommerce/shippings?version=%s';

    private $version;

    public function __construct($version)
    {
        $this->version = $version;
    }

    public function get_shipping_rates(array $package)
    {
        $response = wp_remote_post(
            sprintf(
                self::TEEZILYPLUS_API_SHIPPING_RATES_URL,
                $this->version
            ),
            [
                'timeout' => 10,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => isset($package) ? json_encode($package) : null,
            ]
        );

        if(is_wp_error($response)) {
            error_log($response->get_error_message());
            return null;
        }

        return json_decode($response['body'], true);
    }
}
