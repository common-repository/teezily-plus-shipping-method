<?php

require_once 'teezilyplus-shipping-api.php';

// Documentation at https://woocommerce.com/document/shipping-method-api/
// Check here for available hooks (action) and filters https://woocommerce.github.io/code-reference/hooks/hooks.html
class Teezilyplus_Shipping_Method extends WC_Shipping_Method
{
    const WOO_TRUE = 'yes';
    const WOO_FALSE = 'no';

    const DEFAULT_ENABLED = self::WOO_TRUE;
    const DEFAULT_OVERRIDE = self::WOO_TRUE;
    const VERSION = '0.1.0';

    private $shipping_enabled;
    private $shipping_override;
    private $teezilyplusApiClient;
    private $isTeezilyplusPackage;

    // class constructor
    public function __construct()
    {
        parent::__construct();

        $this->id = 'teezilyplus_shipping';
        $this->method_title = 'Teezily Plus Shipping';
        $this->method_description = 'Calculate live shipping rates based on actual Teezily Plus shipping costs. This version of the Plugin is an Alpha version. There might be a small difference in the shipping prices displayed on WooCommerce and the final price. Some shipping prices for non-Teezily product could be overridden. Only store with default currencies USD or EUR are supported';
        $this->title = 'Teezily Plus Shipping';
        $this->teezilyplusApiClient = new Teezilyplus_Shipping_API(self::VERSION);

        $this->init();

        $this->shipping_enabled = isset($this->settings['enabled']) ? $this->settings['enabled'] : self::DEFAULT_ENABLED;
        $this->shipping_override = isset($this->settings['override_defaults']) ? $this->settings['override_defaults'] : self::DEFAULT_OVERRIDE;
    }

    // Setting form for /wp-admin/admin.php?page=wc-settings&tab=shipping&section=teezilyplus_shipping
    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => 'Enabled',
                'type' => 'checkbox',
                'label' => 'Enable Teezily Plus Shipping Method plugin',
                'default' => self::DEFAULT_ENABLED,
            ],
            'override_defaults' => [
                'title' => 'Override',
                'type' => 'checkbox',
                'label' => 'Override standard WooCommerce shipping rates',
                'default' => self::DEFAULT_OVERRIDE,
            ],
        ];
    }

    function init()
    {
        $this->init_form_fields();
        $this->init_settings();

        // Register form's settings in admin
        add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);

        // Register new Tzplus shipping methods
        add_action('woocommerce_load_shipping_methods', [$this, 'load_shipping_methods']);

        add_filter('woocommerce_shipping_methods', [$this, 'add_teezilyplus_shipping_method']);

        add_filter('woocommerce_cart_shipping_packages', [$this, 'calculate_shipping_rates']);
    }

    function add_teezilyplus_shipping_method($methods)
    {
        return self::WOO_TRUE === $this->shipping_override && true === $this->isTeezilyplusPackage
            ? []
            : $methods;
    }

    function load_shipping_methods($package)
    {
        $this->isTeezilyplusPackage = false;

        if (!$package) {
            WC()->shipping()->register_shipping_method($this);

            return;
        }

        if (self::WOO_FALSE === $this->enabled) {
            return;
        }

        if (isset($package['managed_by_teezilyplus']) && true === $package['managed_by_teezilyplus']) {
            if (self::WOO_TRUE === $this->shipping_override) {
                WC()->shipping()->unregister_shipping_methods();
            }

            $this->isTeezilyplusPackage = true;

            WC()->shipping()->register_shipping_method($this);
        }
    }

    public function calculate_shipping_rates($packages = [])
    {
        if ($this->shipping_enabled !== self::WOO_TRUE) {
            return $packages;
        }

        $requestParameters = [
            'skus' => [],
            'address' => [],
        ];
        foreach ($packages as $package) {
            // Collect skus and quantity
            foreach ($package['contents'] as $variation) {
                /** @var WC_Product_Variation $productVariation */
                if ($variation && $variation['data']) {
                    $productVariation = $variation['data'];

                    if (!isset($requestParameters['skus'][$productVariation->get_sku()])) {
                        $requestParameters['skus'][$productVariation->get_sku()] = [
                            'sku' => $productVariation->get_sku(),
                            'quantity' => $variation['quantity'],
                        ];
                    } else {
                        $requestParameters['skus'][$productVariation->get_sku()] = [
                            'sku' => $productVariation->get_sku(),
                            'quantity' => $requestParameters['skus'][$productVariation->get_sku()]['quantity'] + $variation['quantity'],
                        ];
                    }
                }
            }
            $requestParameters['address'] = [
                'country' => $package['destination']['country'],
                'state' => $package['destination']['state'],
                'zip' => isset($package['destination']['postcode']) ? $package['destination']['postcode'] : null,
            ];
        }

        if (!count($requestParameters['address'])) {
            return $packages;
        }


        // Collect shipping rates for found skus
        $teezilyplusShippingRates = $this->teezilyplusApiClient->get_shipping_rates(
            [
                'items' => $requestParameters['skus'],
                'country' => $requestParameters['address']['country'],
                'currency' => get_woocommerce_currency(),
                'state' => $requestParameters['address']['state'],
                'zip' => isset($requestParameters['address']['postcode']) ? $requestParameters['address']['postcode'] : null,
            ]
        );

        if (null === $teezilyplusShippingRates || empty($teezilyplusShippingRates['skus'])) {
            return $packages;
        }

        $splittedVariations = [
            'other' => [],
            'teezilyplus' => [],
        ];

        foreach ($packages as $package) {
            foreach ($package['contents'] as $variation) {
                /** @var WC_Product_Variation $productVariation */
                $productVariation = $variation['data'];

                if (in_array($productVariation->get_sku(), $teezilyplusShippingRates['skus'])) {
                    // $expressRate = isset($teezilyplusShippingRates['shipping_express'])
                    //     ? $teezilyplusShippingRates['shipping_express']
                    //     : null;

                    $splittedVariations['teezilyplus']['shipping_rates'] = [
                        'standard' => $teezilyplusShippingRates['shipping_standard']
                        // 'express' => $expressRate,
                    ];
                    $splittedVariations['teezilyplus']['variations'][] = $variation;
                } else {
                    $splittedVariations['other']['variations'][] = $variation;
                }
            }
        }

        $splittedPackages = [];

        foreach ($packages as $package) {
            foreach ($splittedVariations as $variationOwner => $splittedVariation) {
                if (!count($splittedVariation)) {
                    continue;
                }

                $splittedPackage = $package;
                $splittedPackage['contents_cost'] = 0;
                $splittedPackage['contents'] = [];

                if ('teezilyplus' === $variationOwner) {
                    $splittedPackage['managed_by_teezilyplus'] = true;
                    $splittedPackage['teezilyplus_shipping_rates'] = $splittedVariation['shipping_rates'];
                }

                foreach ($splittedVariation['variations'] as $variation) {
                    /** @var WC_Product_Variation $productVariation */
                    $productVariation = $variation['data'];

                    $splittedPackage['contents'][] = $variation;

                    if ($productVariation->needs_shipping() && isset($variation['line_total'])) {
                        $splittedPackage['contents_cost'] += $variation['line_total'];
                    }
                }

                $splittedPackages[] = $splittedPackage;
            }
        }

        return $splittedPackages;
    }

    public function calculate_shipping($package = [])
    {
        if (isset($package['managed_by_teezilyplus']) && $package['managed_by_teezilyplus'] === true) {
            $this->add_rate([
                'id' => $this->id . '_s',
                'label' => 'Standard',
                'cost' => $package['teezilyplus_shipping_rates']['standard']['cost_cents'] / 100,
                'calc_tax' => 'per_order',
            ]);
        }

        // if (isset($package['managed_by_teezilyplus']) && $package['managed_by_teezilyplus'] === true && isset($package['teezilyplus_shipping_rates']['express'])) {
        //     $this->add_rate([
        //         'id' => $this->id . '_e',
        //         'label' => 'Express',
        //         'cost' => $package['teezilyplus_shipping_rates']['express']['cost_cents'] / 100,
        //         'calc_tax' => 'per_order',
        //     ]);
        // }
    }
}
