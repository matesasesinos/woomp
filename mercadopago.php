<?php
/*
 * Plugin Name: Mercadopago Payment Gateway
 * Plugin URI: https://genosha.com.ar
 * Description: Pagos para Mercadopago.
 * Author: Genosha
 * Author URI: http://genosha.com.ar
 * Version: 1.0.1
 * */
define('BASE_PATH', plugin_dir_path(__FILE__));
define('BASE_URL', plugin_dir_url(__FILE__));


require BASE_PATH . 'vendor/autoload.php';

// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}



function genosha_mercadopago( $gateways ) {
	$gateways[] = 'WC_Gateway_Mercadopago'; //convencion para nombre de clase WC_Gateway_aca_el_nombre_que_quieras
	return $gateways;
}

add_filter( 'woocommerce_payment_gateways', 'genosha_mercadopago' );

function wc_genosha_mercadopago_links( $links ) {

	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=woo_mercadopago' ) . '">' . __( 'Configurar', 'genosha' ) . '</a>'
	);

	return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_genosha_mercadopago_links' );



add_action( 'plugins_loaded', 'mercadopago_init', 11 );

function mercadopago_init() {

    class WC_Gateway_Mercadopago extends WC_Payment_Gateway 
    {
        //constructor
        public function __construct()
        {
            $this->id = 'woo_mercadopago'; //id del pago
            $this->icon = BASE_URL . 'img/logo.png'; //icono que se muestra en el checkout
            $this->has_fields = true; //para crear el formulario personalizado
            $this->method_title = 'Mercadopago'; 
            $this->method_description ='Pagar con tarjeta de crédito y débito (solo Argentina)';

            $this->support = ['products']; //los pagos pueden soportar productos (products), suscripciones (subscriptions), reembolsos (refunds), en este caso es solo para productos.

            $this->init_form_field(); //inicia todas las opciones
            $this->init_settings(); //iniciamos las opciones del plugin
            
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->testmode = 'yes' === $this->get_option('testmode');
            $this->public_key = $this->get_option('public_key');
            $this->access_token = $this->get_option('access_token');

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) ); //guardamos las opciones
            add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) ); //gracias

			add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 ); //emails

            add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) ); //registrmos los scripts y css
            
        }

        //opciones del plugin
        public function init_form_field()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Activar/Desactivar',
                    'label'       => 'Activar Mercadopago',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'title' => array(
                    'title'       => 'Titulo',
                    'type'        => 'text',
                    'description' => 'Titulo que verán los clientes durante el checkout.',
                    'default'     => 'Tarjeta de Crédito/Débito',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Descripción',
                    'type'        => 'textarea',
                    'description' => 'Descripción que verán los clientes durante el checkout.',
                    'default'     => 'Pagar con tarjeta de crédito o débito mediante Mercadopago.',
                ),
                'testmode' => array(
                    'title'       => 'Test mode',
                    'label'       => 'Activar Modo Test',
                    'type'        => 'checkbox',
                    'description' => 'Para pruebas del plugin.',
                    'default'     => 'yes',
                    'desc_tip'    => true,
                ),
                'public_key' => array(
                    'title'       => 'Public Key',
                    'type'        => 'text',
                    'default'     => 'TEST-6ffb3217-7b90-4bd1-acdb-b7b0e29fdd5d',
                    'description' => '<a href="https://www.mercadopago.com.ar/developers/panel/credentials/" target="_blank">Obtener credenciales </a>'
                ),
                'access_token' => array(
                    'title'       => 'Private Key',
                    'type'        => 'text',
                    'default'     => 'TEST-3842866019040970-072606-a5ade4227f1684923bb85ee2bd515971-79844490',
                    'description' => '<a href="https://www.mercadopago.com.ar/developers/panel/credentials/" target="_blank">Obtener credenciales </a>'
                )
            );
        }

        //formulario
        public function payment_field() 
        {

        }

        //scripts y css
        public function payment_scripts()
        {
            if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
                return;
            }
         
            // if our payment gateway is disabled, we do not have to enqueue JS too
            if ( 'no' === $this->enabled ) {
                return;
            }
         
            // no reason to enqueue JavaScript if API keys are not set
            if ( empty( $this->access_token ) || empty( $this->public_key ) ) {
                return;
            }
         
            // do not work with card detailes without SSL unless your website is in a test mode
            if ( ! is_ssl() ) {
                return;
            }
         
            // let's suppose it is our payment processor JavaScript that allows to obtain a token
            wp_enqueue_script( 'mlstatic_js', 'https://secure.mlstatic.com/sdk/javascript/v1/mercadopago.js' );
         
            wp_register_script( 'woocommerce_mp', plugins_url( 'mp.js', __FILE__ ), array( 'jquery', 'mlstatic_js' ) );
            // in most payment processors you have to use PUBLIC KEY to obtain a token
            wp_localize_script( 'woocommerce_mp', 'mp_params', array(
                'public_key' => $this->public_key
            ) );
         
            wp_enqueue_script( 'woocommerce_mp' );    
        }

        //validacion
        public function validate_fields()
        {

        }

        //proceso de pago
        public function proccess_payment( $order_id )
        {
            
            if ( $this->description ) {
                if ( $this->testmode ) {
                    $this->description .= ' TEST MODE ENABLED. In test mode, you can use the card numbers listed in <a href="#" target="_blank" rel="noopener noreferrer">documentation</a>.';
                    $this->description  = trim( $this->description );
                }
                echo wpautop( wp_kses_post( $this->description ) );
            }

            echo '<fieldset id="wc-' . esc_attr( $this->id ) . '-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent;">';

            do_action( 'woocommerce_credit_card_form_start', $this->id );

            echo '  <p>
            <label for="description">Descripción</label>                        
            <input type="text" name="description" id="description" value="Ítem seleccionado"/>
        </p>                    
        <p>
            <label for="transaction_amount">Monto a pagar</label>                        
            <input name="transaction_amount" id="transaction_amount" value="100"/>
        </p>
        <p>
            <label for="cardNumber">Número de la tarjeta</label>
            <input type="text" id="cardNumber" data-checkout="cardNumber" onselectstart="return false" onpaste="return false" onCopy="return false" onCut="return false" onDrag="return false" onDrop="return false" autocomplete=off />
        </p>
        <p>
            <label for="cardholderName">Nombre y apellido</label>
            <input type="text" id="cardholderName" data-checkout="cardholderName" />
        </p>                                    
        <p>
            <label for="cardExpirationMonth">Mes de vencimiento</label>
            <input type="text" id="cardExpirationMonth" data-checkout="cardExpirationMonth" onselectstart="return false" onpaste="return false" onCopy="return false" onCut="return false" onDrag="return false" onDrop="return false" autocomplete=off />
        </p>
        <p>
            <label for="cardExpirationYear">Año de vencimiento</label>
            <input type="text" id="cardExpirationYear" data-checkout="cardExpirationYear" onselectstart="return false" onpaste="return false" onCopy="return false" onCut="return false" onDrag="return false" onDrop="return false" autocomplete=off />
        </p>
        <p>
            <label for="securityCode">Código de seguridad</label>
            <input type="text" id="securityCode" data-checkout="securityCode" onselectstart="return false" onpaste="return false" onCopy="return false" onCut="return false" onDrag="return false" onDrop="return false" autocomplete=off />
        </p>
        <p>
           <label for="installments">Cuotas</label>
           <select id="installments" class="form-control" name="installments"></select>
        </p>
        <p>
            <label for="docType">Tipo de documento</label>
            <select id="docType" data-checkout="docType"></select>
        </p>
        <p>
            <label for="docNumber">Número de documento</label>
            <input type="text" id="docNumber" data-checkout="docNumber"/>
        </p>
        <p>
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" value="test@test.com"/>
        </p>  
        
        <input type="hidden" name="payment_method_id" id="payment_method_id"/>';
        do_action( 'woocommerce_credit_card_form_end', $this->id );
 
        echo '<div class="clear"></div></fieldset>';

        }

        //gracias
        public function thankyou_page() {
			if ( $this->instructions ) {
				echo wpautop( wptexturize( $this->instructions ) );
			}
        }

        //email
        public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		
			if ( $this->instructions && ! $sent_to_admin && $this->id === $order->payment_method ) {
				echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
			}
		}

        //webhooks, en caso de necesitarse
        public function webhooks()
        {

        }

    }

}