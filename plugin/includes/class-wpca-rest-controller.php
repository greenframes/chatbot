<?php

if (! defined('ABSPATH')) {
    exit;
}

class WPCA_REST_Controller
{
    private $chat_service;
    private $knowledge_base;

    public function __construct(WPCA_Chat_Service $chat_service, WPCA_Knowledge_Base $knowledge_base)
    {
        $this->chat_service = $chat_service;
        $this->knowledge_base = $knowledge_base;
    }

    public function register()
    {
        add_action('rest_api_init', array($this, 'routes'));
    }

    public function routes()
    {
        register_rest_route(
            'wpca/v1',
            '/chat',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'permission_callback' => '__return_true',
                'callback' => array($this, 'chat'),
            )
        );

        register_rest_route(
            'wpca/v1',
            '/status',
            array(
                'methods' => WP_REST_Server::READABLE,
                'permission_callback' => '__return_true',
                'callback' => array($this, 'status'),
            )
        );

        register_rest_route(
            'wpca/v1',
            '/cari-quip',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'permission_callback' => '__return_true',
                'callback' => array($this, 'cari_quip'),
            )
        );
    }

    public function chat(WP_REST_Request $request)
    {
        if ($this->is_rate_limited()) {
            return new WP_REST_Response(
                array('message' => __('Zu viele Anfragen in kurzer Zeit. Bitte versuche es gleich noch einmal.', 'company-faq-chatbot')),
                429
            );
        }

        $history = $request->get_param('history');
        if (! is_array($history)) {
            $history = array();
        }

        $message = $request->get_param('message');
        if (! is_scalar($message)) {
            return new WP_REST_Response(array('message' => __('Bitte sende eine gueltige Textnachricht.', 'company-faq-chatbot')), 400);
        }

        $message = trim((string) $message);
        if ('' === $message) {
            return new WP_REST_Response(array('message' => __('Bitte stelle eine Frage.', 'company-faq-chatbot')), 400);
        }

        if (mb_strlen($message) > 2000) {
            return new WP_REST_Response(array('message' => __('Die Nachricht ist zu lang. Bitte formuliere sie kuerzer.', 'company-faq-chatbot')), 400);
        }

        $language = sanitize_text_field((string) $request->get_param('language'));

        $result = $this->chat_service->answer($message, $history, $language);
        if (is_wp_error($result)) {
            return new WP_REST_Response(array('message' => $result->get_error_message()), 400);
        }

        return new WP_REST_Response($result, 200);
    }

    public function cari_quip(WP_REST_Request $request)
    {
        if ($this->is_rate_limited()) {
            return new WP_REST_Response(
                array('message' => __('Zu viele Anfragen in kurzer Zeit. Bitte versuche es gleich noch einmal.', 'company-faq-chatbot')),
                429
            );
        }

        $last_quip = sanitize_textarea_field((string) $request->get_param('lastQuip'));
        $language = sanitize_text_field((string) $request->get_param('language'));
        $fallbacks = (0 === strpos(strtolower($language), 'en'))
            ? array('Need a quick answer? I am right here.', 'Ask me anything about the next step.', 'Small pause, clear answer.')
            : array('Brauchst du eine schnelle Antwort? Ich bin da.', 'Frag mich gern nach dem naechsten Schritt.', 'Kleine Pause, klare Antwort.');
        $options = array_values(array_filter($fallbacks, static function ($entry) use ($last_quip) {
            return $entry !== $last_quip;
        }));
        $source = ! empty($options) ? $options : $fallbacks;
        $index = function_exists('wp_rand') ? wp_rand(0, count($source) - 1) : array_rand($source);

        return new WP_REST_Response(array('quip' => $source[$index]), 200);
    }

    public function status()
    {
        $settings = $this->knowledge_base->get_settings();

        return new WP_REST_Response(
            array(
                'enabled' => ! empty($settings['enabled']),
                'configured' => ! empty($settings['openai_api_key']),
            ),
            200
        );
    }

    private function is_rate_limited()
    {
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $key = 'wpca_rate_' . md5($ip . '|' . wp_salt('nonce'));
        $count = (int) get_transient($key);

        if ($count >= 120) {
            return true;
        }

        set_transient($key, $count + 1, 10 * MINUTE_IN_SECONDS);
        return false;
    }
}
