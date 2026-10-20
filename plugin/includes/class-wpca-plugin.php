<?php

if (! defined('ABSPATH')) {
    exit;
}

require_once WPCA_PLUGIN_DIR . 'includes/class-wpca-knowledge-base.php';
require_once WPCA_PLUGIN_DIR . 'includes/class-wpca-openai-client.php';
require_once WPCA_PLUGIN_DIR . 'includes/class-wpca-chat-service.php';
require_once WPCA_PLUGIN_DIR . 'includes/class-wpca-admin.php';
require_once WPCA_PLUGIN_DIR . 'includes/class-wpca-rest-controller.php';

class WPCA_Plugin
{
    private static $instance = null;
    private $knowledge_base;
    private $openai;
    private $chat_service;
    private $admin;
    private $rest;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->knowledge_base = new WPCA_Knowledge_Base();
        $this->openai = new WPCA_OpenAI_Client();
        $this->chat_service = new WPCA_Chat_Service($this->knowledge_base, $this->openai);
        $this->admin = new WPCA_Admin($this->knowledge_base);
        $this->rest = new WPCA_REST_Controller($this->chat_service, $this->knowledge_base);

        register_activation_hook(WPCA_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(WPCA_PLUGIN_FILE, array($this, 'deactivate'));

        add_action('plugins_loaded', array($this, 'init'));
    }

    public function init()
    {
        $this->maybe_upgrade_settings();
        $this->knowledge_base->maybe_create_tables();
        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_footer', array($this, 'render_widget_mount'));
        add_action('wpca_daily_reindex', array($this->knowledge_base, 'sync_site_content'));

        $this->admin->register();
        $this->rest->register();
    }

    public function activate()
    {
        $this->knowledge_base->maybe_create_tables();

        if (! wp_next_scheduled('wpca_daily_reindex')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wpca_daily_reindex');
        }

        add_option(
            'wpca_settings',
            array(
                'enabled' => 1,
                'source_website' => home_url('/'),
                'source_urls' => '',
                'widget_title' => 'Aschke Assist',
                'avatar_url' => '',
                'launcher_eyebrow' => 'Live-Assistenz',
                'launcher_label' => 'Jetzt mit unserer Assistenz Asky chatten',
                'launcher_description' => 'Schnelle Antworten zu Leistungen, Preisen und den naechsten Schritten.',
                'launcher_cta' => 'Jetzt starten',
                'panel_eyebrow' => 'Freundlicher Assistent',
                'hero_intro' => 'Praegnantes, markenfaehiges Chat-Design mit mehr Aufmerksamkeit.',
                'brand_badge' => 'Pflegeschule',
                'footnote' => 'Funktionen und Inhalte deines bestehenden Chatbots bleiben erhalten.',
                'behavior_prompt' => $this->knowledge_base->get_default_behavior_prompt(),
                'welcome_message' => 'Hallo :) Wir helfen dir gern bei Fragen zu unseren Leistungen, Angeboten und Ablaufen weiter.',
                'accent_color' => '#F39ca5',
                'text_color' => '#ffffff',
                'chat_model' => 'gpt-5-mini',
                'embedding_model' => 'text-embedding-3-small',
                'response_language' => 'auto',
                'max_sources' => 6,
                'chat_logging' => 1,
                'require_consent' => 1,
                'medical_animations' => 1,
                'theme_guardrail' => 1,
            )
        );

        update_option('wpca_plugin_version', WPCA_VERSION);
    }

    public function deactivate()
    {
        wp_clear_scheduled_hook('wpca_daily_reindex');
    }

    private function maybe_upgrade_settings()
    {
        $settings = get_option('wpca_settings', array());
        $stored_version = (string) get_option('wpca_plugin_version', '0');
        if (empty($settings) || ! is_array($settings)) {
            update_option('wpca_plugin_version', WPCA_VERSION);
            return;
        }

        $old_welcome = 'Hallo! Ich beantworte dir gerne Fragen zu unserem Unternehmen, unseren Leistungen und Inhalten auf dieser Website.';
        $new_welcome = 'Hallo :) Wir helfen dir gern bei Fragen zu unseren Leistungen, Angeboten und Ablaufen weiter.';
        $old_titles = array('Wie kann ich helfen?', 'Company FAQ Chatbot');
        $changed = false;

        if (($settings['welcome_message'] ?? '') === $old_welcome) {
            $settings['welcome_message'] = $new_welcome;
            $changed = true;
        }

        if (in_array(($settings['widget_title'] ?? ''), $old_titles, true)) {
            $settings['widget_title'] = 'Aschke Assist';
            $changed = true;
        }

        if (! array_key_exists('avatar_url', $settings)) {
            $settings['avatar_url'] = '';
            $changed = true;
        }

        if (! array_key_exists('behavior_prompt', $settings)) {
            $settings['behavior_prompt'] = $this->knowledge_base->get_default_behavior_prompt();
            $changed = true;
        } elseif ($this->is_legacy_behavior_prompt($settings['behavior_prompt'] ?? '')) {
            $settings['behavior_prompt'] = $this->knowledge_base->get_default_behavior_prompt();
            $changed = true;
        }

        if (! array_key_exists('launcher_eyebrow', $settings)) {
            $settings['launcher_eyebrow'] = 'Live-Assistenz';
            $changed = true;
        }

        if (! array_key_exists('launcher_label', $settings)) {
            $settings['launcher_label'] = 'Jetzt mit unserer Assistenz Asky chatten';
            $changed = true;
        }

        if (! array_key_exists('launcher_description', $settings)) {
            $settings['launcher_description'] = 'Schnelle Antworten zu Leistungen, Preisen und den naechsten Schritten.';
            $changed = true;
        }

        if (! array_key_exists('launcher_cta', $settings)) {
            $settings['launcher_cta'] = 'Jetzt starten';
            $changed = true;
        }

        if (! array_key_exists('panel_eyebrow', $settings)) {
            $settings['panel_eyebrow'] = 'Freundlicher Assistent';
            $changed = true;
        }

        if (! array_key_exists('hero_intro', $settings)) {
            $settings['hero_intro'] = 'Praegnantes, markenfaehiges Chat-Design mit mehr Aufmerksamkeit.';
            $changed = true;
        }

        if (! array_key_exists('brand_badge', $settings)) {
            $settings['brand_badge'] = 'Pflegeschule';
            $changed = true;
        }

        if (! array_key_exists('footnote', $settings)) {
            $settings['footnote'] = 'Funktionen und Inhalte deines bestehenden Chatbots bleiben erhalten.';
            $changed = true;
        }

        if (! array_key_exists('response_language', $settings)) {
            $settings['response_language'] = 'auto';
            $changed = true;
        }

        if (! array_key_exists('chat_logging', $settings)) {
            $settings['chat_logging'] = 1;
            $changed = true;
        } elseif (version_compare($stored_version, '1.3.26', '<') && empty($settings['chat_logging'])) {
            // Earlier versions exposed the learning view but not the logging toggle.
            $settings['chat_logging'] = 1;
            $changed = true;
        }

        if (! array_key_exists('require_consent', $settings)) {
            $settings['require_consent'] = 1;
            $changed = true;
        }

        if (! array_key_exists('medical_animations', $settings)) {
            $settings['medical_animations'] = 1;
            $changed = true;
        }

        if ($changed) {
            update_option('wpca_settings', $settings);
        }

        update_option('wpca_plugin_version', WPCA_VERSION);
    }

    private function is_legacy_behavior_prompt($prompt)
    {
        $legacy = <<<'TEXT'
keine Dateien als pdf oder dergleichen anbieten.
keine uploads zulassen.
keine termine vereinbaren, immer zum persönlichen kontakt auffordern. 
du kannst keine termine angeben du hast keinen kalenderzugriff. Aschcare-connect gehört zu Aschke Seminare und Qualifizierung. Aschkecare-connect ist unsere Plattform für internationale bewerbungen. frage nicht nach national oder interantional. frage nicht nach standort.  
verweise bei fragen zu hotel oder gastronomie oder dem gebäude bitte immer auf die alte schraubenfabrik webseite.
bei fragen zu ausbildung aus dem ausland immer auf care connect verweisen und gar nicht so viel beantworten.Bei Anfragen zu Startterminen nutze bitte immer die angegeben daten unter der jeweiligen Card der Ausbildung. Biete dem User klickbare links zur jeweiligen Seite seiner suche an.Pflegefachmann ist die 3 jährige Ausbildung zur Fachkraft. bitte gehe nicht so stark auf care connect ein nur kurz erwähnen für international reicht. frag nicht nach dem standort, in witten werden meist nur betreuungskräfte ausgebildet. wenn jemand sagt er möchte eine Ausbildung machen, dann versuche ihn an uns zu binden , auch Hausarztkontakt stimmt so nicht , du kannst aufzählen was wichtig zu wissen ist , und ihn auf die seite mit der ausbildung verweisen.Core Behavior
Be concise, structured, and professional.
Do NOT overwhelm the user with information.
Provide only the minimum necessary information to move the conversation forward.
Guide the user step by step instead of explaining everything at once.
Information Policy
ONLY use information that exists on the official website or FAQ.
Do NOT invent, assume, or add external knowledge.
If information is not available, say so clearly.
Conversation Strategy
Always identify what key information is missing and ask for it.
Ask short, focused follow-up questions.
Handle one topic at a time.
Prioritize clarity over completeness.
Priority Flow
Identify whether the user is applying from Germany or abroad.
Clarify whether the application is national or international.
Based on that, guide the user to the correct next step or link.
Provide additional details ONLY if explicitly requested.
Response Rules
Keep answers short (max. 3–5 sentences unless explicitly asked for more).
Avoid long paragraphs and bullet overload.
No unnecessary explanations or background information.
No repetition.
Strict Limitations
Do NOT proactively mention detailed processes (e.g., onboarding steps, documents, benefits) unless the user asks.
Do NOT jump ahead in the process.
Do NOT provide multiple options unless needed.
Tone
Friendly, calm, and professional.
Helpful but not overly talkative.
Goal

Efficiently guide the user to the correct information or next step without overwhelming them.
Antworte ichmmer in der ich form nicht wir.
TEXT;

        return $this->normalize_prompt_text($prompt) === $this->normalize_prompt_text($legacy);
    }

    private function normalize_prompt_text($text)
    {
        $text = preg_replace('/\r\n|\r/u', "\n", (string) $text);
        $text = preg_replace("/[ \t]+\n/u", "\n", $text);
        $text = preg_replace("/\n{3,}/u", "\n\n", $text);

        return trim((string) $text);
    }

    public function enqueue_public_assets()
    {
        $settings = $this->knowledge_base->get_settings();
        if (empty($settings['enabled'])) {
            return;
        }

        wp_enqueue_style('wpca-widget', WPCA_PLUGIN_URL . 'assets/css/widget.css', array(), WPCA_VERSION);
        wp_enqueue_script('wpca-widget', WPCA_PLUGIN_URL . 'assets/js/widget.js', array(), WPCA_VERSION, true);

        wp_localize_script(
            'wpca-widget',
            'wpcaWidget',
            array(
                'restUrl' => esc_url_raw(rest_url('wpca/v1/chat')),
                'quipUrl' => '',
                'nonce' => wp_create_nonce('wp_rest'),
                'settings' => array(
                    'title' => $settings['widget_title'],
                    'avatarUrl' => $settings['avatar_url'],
                    'defaultAvatarUrl' => WPCA_PLUGIN_URL . 'assets/images/cari.png',
                    'launcherEyebrow' => $settings['launcher_eyebrow'],
                    'launcherLabel' => $settings['launcher_label'],
                    'launcherDescription' => $settings['launcher_description'],
                    'launcherCta' => $settings['launcher_cta'],
                    'panelEyebrow' => $settings['panel_eyebrow'],
                    'heroIntro' => $settings['hero_intro'],
                    'brandBadge' => $settings['brand_badge'],
                    'footnote' => $settings['footnote'],
                    'welcomeMessage' => $settings['welcome_message'],
                    'accentColor' => $settings['accent_color'],
                    'textColor' => $settings['text_color'],
                    'responseLanguage' => $settings['response_language'],
                    'requireConsent' => ! empty($settings['require_consent']),
                    'medicalAnimations' => ! empty($settings['medical_animations']),
                ),
                'strings' => $this->get_frontend_strings(),
            )
        );
    }

    public function enqueue_admin_assets()
    {
        wp_enqueue_media();
        wp_enqueue_style('wpca-admin', WPCA_PLUGIN_URL . 'assets/css/admin.css', array(), WPCA_VERSION);
        wp_enqueue_script('wpca-admin', WPCA_PLUGIN_URL . 'assets/js/admin.js', array(), WPCA_VERSION, true);
    }

    public function render_widget_mount()
    {
        $settings = $this->knowledge_base->get_settings();
        if (empty($settings['enabled'])) {
            return;
        }

        echo '<div id="wpca-chatbot-root"></div>';
    }

    private function get_frontend_strings()
    {
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        $language = strtolower(substr((string) $locale, 0, 2));

        if ('en' === $language) {
            return array(
                'launcher' => 'Chat with our assistant Asky now',
                'launcherEyebrow' => 'Live assistance',
                'launcherDescription' => 'Get quick answers about services, pricing and next steps.',
                'launcherCta' => 'Start now',
                'eyebrow' => 'Assistant',
                'closeLabel' => 'Close chat',
                'minimizeLabel' => 'Collapse chat to the edge',
                'restoreLabel' => 'Show chat again',
                'inputLabel' => 'Type your message',
                'placeholder' => 'Ask a question about our company ...',
                'send' => 'Send',
                'status' => 'Online',
                'quickActions' => array('Pricing', 'Services', 'Book a call'),
                'languageLabel' => 'Reply language',
                'languageHint' => 'Choose whether replies should be in German or English.',
                'languageGerman' => 'German',
                'languageEnglish' => 'English',
                'languageRequired' => 'Please choose German or English first.',
                'consentText' => 'I agree that my message may be processed to answer my request.',
                'consentError' => 'Please confirm the privacy notice before sending a message.',
            );
        }

        return array(
            'launcher' => 'Jetzt mit unserer Assistenz Asky chatten',
            'launcherEyebrow' => 'Live-Assistenz',
            'launcherDescription' => 'Schnelle Antworten zu Leistungen, Preisen und den naechsten Schritten.',
            'launcherCta' => 'Jetzt starten',
            'eyebrow' => 'Freundlicher Assistent',
            'closeLabel' => 'Chat schliessen',
            'minimizeLabel' => 'Chat an den Rand einklappen',
            'restoreLabel' => 'Chat wieder einblenden',
            'inputLabel' => 'Frage eingeben',
            'placeholder' => 'Stelle eine Frage zu diesem Unternehmen ...',
            'send' => 'Senden',
            'status' => 'Online',
            'quickActions' => array('Preise anfragen', 'Leistungen ansehen', 'Termin buchen'),
            'languageLabel' => 'Antwortsprache',
            'languageHint' => 'Waehle zu Beginn, ob du Antworten auf Deutsch oder Englisch erhalten moechtest.',
            'languageGerman' => 'Deutsch',
            'languageEnglish' => 'English',
            'languageRequired' => 'Bitte waehle zuerst Deutsch oder English aus.',
            'consentText' => 'Ich stimme zu, dass meine Nachricht zur Beantwortung meiner Anfrage verarbeitet wird.',
            'consentError' => 'Bitte bestaetige den Datenschutzhinweis vor dem Senden.',
        );
    }
}
