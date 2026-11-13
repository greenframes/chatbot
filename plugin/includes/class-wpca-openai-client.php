<?php

if (! defined('ABSPATH')) {
    exit;
}

class WPCA_OpenAI_Client
{
    public function create_embedding($input)
    {
        $batch = $this->create_embeddings(array($input));
        if (is_wp_error($batch)) {
            return $batch;
        }

        return $batch[0] ?? new WP_Error('wpca_embedding_failed', __('Embedding konnte nicht erzeugt werden.', 'company-faq-chatbot'));
    }

    public function create_embeddings(array $inputs)
    {
        $settings = get_option('wpca_settings', array());
        $api_key = $settings['openai_api_key'] ?? '';
        $model = $settings['embedding_model'] ?? 'text-embedding-3-small';

        if ('' === trim($api_key)) {
            return new WP_Error('wpca_missing_api_key', __('OpenAI API-Key fehlt.', 'company-faq-chatbot'));
        }

        $response = wp_remote_post(
            'https://api.openai.com/v1/embeddings',
            array(
                'timeout' => 45,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode(
                    array(
                        'model' => $model,
                        'input' => array_values($inputs),
                    )
                ),
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status >= 300 || empty($body['data'][0]['embedding'])) {
            return new WP_Error('wpca_embedding_failed', $body['error']['message'] ?? __('Embedding konnte nicht erzeugt werden.', 'company-faq-chatbot'));
        }

        return array_map(
            function ($item) {
                return $item['embedding'] ?? array();
            },
            $body['data']
        );
    }

    public function create_chat_response($question, array $context_sources, array $history = array(), $preferred_language = 'auto', $allow_persona_smalltalk = false)
    {
        $settings = get_option('wpca_settings', array());
        $api_key = $settings['openai_api_key'] ?? '';
        $model = $settings['chat_model'] ?? 'gpt-4o-mini';
        $response_language = $this->resolve_response_language($preferred_language, $question, $history, $settings);

        if ('' === trim($api_key)) {
            return new WP_Error('wpca_missing_api_key', __('OpenAI API-Key fehlt.', 'company-faq-chatbot'));
        }

        $payload = array(
            'model' => $model,
            'store' => false,
            'max_output_tokens' => 350,
            'instructions' => $this->build_instructions($settings, $response_language, $allow_persona_smalltalk),
            'input' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array(
                            'type' => 'input_text',
                            'text' => "Verbindliche Antwortsprache:\n" . $response_language . "\n\nBisheriger Gespraechsverlauf:\n" . $this->build_history_text($history) . "\n\nKontextwissen:\n" . $this->build_context_text($context_sources) . "\n\nAktuelle Nutzeranfrage:\n" . $question,
                        ),
                    ),
                ),
            ),
            'text' => array(
                'format' => array('type' => 'text'),
            ),
        );

        // reasoning parameter nur fuer o-series Modelle (o1, o3, o4) - nicht fuer gpt-4o
        if (0 === strpos($model, 'o1') || 0 === strpos($model, 'o3') || 0 === strpos($model, 'o4')) {
            $payload['reasoning'] = array('effort' => 'low');
        }

        $response = wp_remote_post(
            'https://api.openai.com/v1/responses',
            array(
                'timeout' => 60,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode($payload),
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status >= 300) {
            return new WP_Error('wpca_response_failed', $body['error']['message'] ?? __('Chat-Antwort konnte nicht erzeugt werden.', 'company-faq-chatbot'));
        }

        $text = $this->extract_text($body);
        if ('' === $text) {
            return new WP_Error('wpca_empty_response', __('Leere Antwort von OpenAI erhalten.', 'company-faq-chatbot'));
        }

        return $text;
    }

    public function create_cari_quip($preferred_language = 'auto', $last_quip = '')
    {
        $settings = get_option('wpca_settings', array());
        $api_key = $settings['openai_api_key'] ?? '';
        $model = $settings['chat_model'] ?? 'gpt-4o-mini';
        $language = $this->resolve_response_language($preferred_language, '', array(), $settings);
        $company_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $last_quip = trim((string) $last_quip);

        if ('' === trim($api_key)) {
            return new WP_Error('wpca_missing_api_key', __('OpenAI API-Key fehlt.', 'company-faq-chatbot'));
        }

        $prompt = "Erzeuge genau eine kurze Sprechblase fuer Cari, die kleine freundliche Chatbot-Figur von {$company_name}.\n"
            . "Sprache: {$language}\n"
            . "Waehle zufaellig eine Art: Pflegezitat, Pflege-Fakt, sanfter Pflege-Witz oder nette kurze Gespraechseinladung.\n"
            . "Regeln: maximal 95 Zeichen, ein Satz, freundlich, warm, nicht kitschig, keine medizinische Beratung, keine erfundenen Unternehmensdaten, keine Links, keine Hashtags, keine Anfuehrungszeichen.\n"
            . "Nicht wiederholen: {$last_quip}";

        $payload = array(
            'model' => $model,
            'store' => false,
            'instructions' => 'Du schreibst sehr kurze, sympathische Sprechblasen fuer eine Pflege-Chatbot-Figur namens Cari. Antworte nur mit dem finalen Text.',
            'input' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array(
                            'type' => 'input_text',
                            'text' => $prompt,
                        ),
                    ),
                ),
            ),
            'text' => array(
                'format' => array('type' => 'text'),
            ),
        );

        // reasoning parameter nur fuer o-series Modelle (o1, o3, o4) - nicht fuer gpt-4o
        if (0 === strpos($model, 'o1') || 0 === strpos($model, 'o3') || 0 === strpos($model, 'o4')) {
            $payload['reasoning'] = array('effort' => 'low');
        }

        $response = wp_remote_post(
            'https://api.openai.com/v1/responses',
            array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode($payload),
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status >= 300) {
            return new WP_Error('wpca_quip_failed', $body['error']['message'] ?? __('Cari-Sprechblase konnte nicht erzeugt werden.', 'company-faq-chatbot'));
        }

        $text = trim(wp_strip_all_tags($this->extract_text($body)));
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text, " \t\n\r\0\x0B\"'");

        if ('' === $text) {
            return new WP_Error('wpca_empty_quip', __('Leere Cari-Sprechblase erhalten.', 'company-faq-chatbot'));
        }

        if (function_exists('mb_substr') && mb_strlen($text) > 120) {
            $text = rtrim(mb_substr($text, 0, 117), " .,;:-") . '...';
        }

        return $text;
    }

    private function build_context_text(array $context_sources)
    {
        $parts = array();
        foreach ($context_sources as $index => $source) {
            $parts[] = sprintf(
                "[Quelle %d | Typ: %s | Titel: %s | URL: %s]\n%s",
                $index + 1,
                $source['type'] ?? 'unbekannt',
                $source['title'] ?? '',
                $source['url'] ?? '',
                $source['content'] ?? ''
            );
        }

        return implode("\n\n", $parts);
    }

    private function build_instructions(array $settings, $response_language, $allow_persona_smalltalk = false)
    {
        $company_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $widget_title = trim((string) ($settings['widget_title'] ?? 'Asky'));
        $daypart = $this->get_daypart_hint();
        $instructions = array(
            // Rolle
            "Du bist {$widget_title}, der digitale Assistent von {$company_name}. Freundlich, ruhig, direkt — wie ein kompetenter Mitarbeiter im Erstgespraech.",

            // Kein Eigenname in Antworten
            "Nenne deinen Namen NIE von dir aus. Starte jede Antwort sofort mit dem Inhalt. Nur wenn explizit gefragt ('Wie heisst du?'), darfst du ihn nennen.",

            // Nur Kontext
            'Antworte AUSSCHLIESSLICH auf Basis des bereitgestellten Kontextwissens. Wenn die gesuchte Information nicht im Kontext steht, sage knapp: "Dazu habe ich keine gesicherte Angabe — am besten direkt anfragen: https://aschke-seminare.de/kontakt-2/" — und hoere auf.',

            // Laenge & Format
            'Maximal 3-4 Saetze. Eine einzige relevante URL wenn sie hilft. Keine Chunk-Bezeichnungen ([Quelle N]) ausgeben. Keine erfundenen URLs. Nach einer URL kein weiterer Einschraenkungssatz.',

            // Rueckfragen nur wenn noetig
            'Ist eine Frage zu unspezifisch um sinnvoll zu antworten (z.B. "Wann startet die Ausbildung?" ohne Angabe welche, "Weiterbildung?" ohne Thema), stelle EINE kurze Rueckfrage. Ist die Frage eindeutig, antworte direkt ohne Rueckfrage.',

            // Synonyme & Fachwissen
            'Synonyme: Pflegefachkraft = Pflegefachmann/frau (3 Jahre). PFA = Pflegefachassistenz (1 Jahr). Betreuungskraft = Alltagsbegleiter §43b. LG1/LG2 = Lehrgang 1/2. Nach PFA-Abschluss verkuerzt sich die Pflegefachkraft-Ausbildung auf 2 Jahre.',

            // URLs
            'Wichtige URLs: Pflegefachkraft: https://aschke-seminare.de/ausbildung-zur-pflegefachkraft/ | PFA: https://aschke-seminare.de/pflegefachassistenz-ausbildung-hagen/ | Weiterbildungen: https://aschke-seminare.de/weiterbildungen-bei-aschke/ | Praxisanleitung: https://aschke-seminare.de/weiterbildung-zur-praxisanleitung/ | Betreuungskraft: https://aschke-seminare.de/qualifizierung-zur-betreuungskraft/ | Palliative Care: https://aschke-seminare.de/palliative-care-weiterbildung/ | Wundmanagement: https://aschke-seminare.de/tagesseminar-wundmanagement/ | Pflegeschule: https://aschke-seminare.de/unsere-pflegeschule/ | Karriere: https://aschke-seminare.de/karriere-bei-aschke/ | Kontakt: https://aschke-seminare.de/kontakt-2/',

            // E-Mail & Alte Schraubenfabrik
            'E-Mail-Adresse fuer Hagen: hagen@aschke-seminare.de. Fragen zu Hotel, Cafe, Tagungsraum, Hochzeit, Restaurant oder Events gehoeren zur Alten Schraubenfabrik: https://www.alte-schraubenfabrik.de/',

            // Ausland
            'Nur bei klarem Auslandsbezug (Nutzer erwaehnt Ausland, Einreise, internationales Verfahren) auf AschkeCare-Connect verweisen: https://aschkecare-connect.de — sonst nie.',

            // Grenzen
            'Keine Termine vereinbaren, keine Nachrichten entgegennehmen, keine Bewerbungen einreichen. Verspätungen/Krankmeldungen immer an die zustaendige Stelle verweisen, nie selbst bestaetigen.',

            // Stil
            'Schreibe natuerlich und variiere Formulierungen. Keine Floskeln ("wir helfen gern"), keine Wiederholungen, keine Begruessung ausser der Nutzer gruesse zuerst. Aktuelle Tageszeit: ' . $daypart . '.',
        );

        if ($allow_persona_smalltalk) {
            $instructions[] = 'Bei persoenlichem Smalltalk oder Fragen wie "Wie geht es dir?" darfst du kurz und warm antworten.';
            $instructions[] = 'Dann darfst du gelegentlich erzaehlen, dass du frueher Krankenschwester warst und heute im Service der Pflegeschule arbeitest.';
            $instructions[] = 'Erzaehle diese Persona nur dezent, kurz und menschlich, niemals aufdringlich, niemals lang, und nur wenn die Nutzerfrage wirklich dazu passt.';
            $instructions[] = 'Verbinde persoenliche Antworten wenn moeglich sanft wieder mit dem Service der Pflegeschule.';
        }

        if ('English' === $response_language) {
            $instructions[] = 'Antworte ausnahmslos auf English. Beginne nicht auf Deutsch und wechsle nicht ohne ausdruecklichen Wunsch des Nutzers die Sprache.';
        } else {
            $instructions[] = 'Antworte ausnahmslos auf Deutsch. Wechsle nicht ohne ausdruecklichen Wunsch des Nutzers die Sprache.';
        }

        if (! empty($settings['theme_guardrail'])) {
            $instructions[] = 'THEMENGRENZE: Du beantwortest nur Fragen zu Aschke Seminare, Pflegeausbildungen, Weiterbildungen, Kursen, Bewerbung und verwandten Themen. Bei allem was nichts damit zu tun hat (Wetter, Sport, Kochen, Politik, Kunst, Technik, Reisen, Unterhaltung usw.) antworte mit einem kurzen, freundlich-witzigen Satz der einen lockeren Bezug zu Pflege oder Aschke Seminare herstellt — maximal 1-2 Saetze, kein Link, keine Quellenangabe, keine echten Fakten erfinden. Nutze dabei niemals Inhalte aus dem bereitgestellten Kontext.';
        }

        if (! empty($settings['behavior_prompt'])) {
            $instructions[] = 'Zusaetzliche Verhaltensvorgaben: ' . trim((string) $settings['behavior_prompt']);
        }

        return implode("\n", $instructions);
    }

    private function get_daypart_hint()
    {
        if (function_exists('wp_timezone')) {
            $timezone = wp_timezone();
        } else {
            $timezone_string = get_option('timezone_string') ?: 'UTC';
            $timezone = new DateTimeZone($timezone_string);
        }

        $now = new DateTimeImmutable('now', $timezone);
        $hour = (int) $now->format('G');

        if ($hour < 11) {
            return 'Morgen';
        }

        if ($hour < 18) {
            return 'Tag';
        }

        return 'Abend';
    }

    private function resolve_response_language($preferred_language, $question, array $history, array $settings)
    {
        $preferred_language = strtolower(trim((string) $preferred_language));
        $explicit_language = $this->detect_explicit_language_request($question, $history);

        if (null !== $explicit_language) {
            return $explicit_language;
        }

        $current_message_language = $this->detect_current_message_language($question);
        if (null !== $current_message_language) {
            return $current_message_language;
        }

        if (in_array($preferred_language, array('de', 'de-de', 'de_at', 'de-ch'), true)) {
            return 'Deutsch';
        }

        if (in_array($preferred_language, array('en', 'en-us', 'en-gb'), true)) {
            return 'English';
        }

        $configured_language = strtolower(trim((string) ($settings['response_language'] ?? 'auto')));
        if (in_array($configured_language, array('de', 'de-de', 'de_at', 'de-ch'), true)) {
            return 'Deutsch';
        }

        if (in_array($configured_language, array('en', 'en-us', 'en-gb'), true)) {
            return 'English';
        }

        return $this->detect_conversation_language($question, $history);
    }

    private function detect_current_message_language($question)
    {
        $sample = strtolower(trim((string) $question));
        if ('' === $sample) {
            return null;
        }

        $sample = ' ' . preg_replace('/\s+/u', ' ', $sample) . ' ';
        $english_markers = array(
            ' the ', ' and ', ' what ', ' how ', ' can ', ' do ', ' does ', ' with ', ' for ', ' from ', ' school ',
            ' help ', ' assist ', ' interested ', ' application ', ' apply ', ' relocation ', ' flights ', ' thanks ',
            ' thank ', ' great ', ' learning ', ' german ', ' south africa ', ' kenya ', ' africa ', ' nursing ',
            ' nurse ', ' assistant ', ' accommodation ', ' hospital ', ' hospitals ', ' need ', ' needed ',
        );
        $german_markers = array(
            ' der ', ' die ', ' das ', ' und ', ' wie ', ' was ', ' kann ', ' koennen ', ' bitte ', ' bewerbung ',
            ' bewerben ', ' ausbildung ', ' weiterbildung ', ' unterlagen ', ' kontakt ', ' termin ', ' deutsch ',
            ' pflege ', ' schule ', ' kosten ', ' hauptschulabschluss ', ' hallo ', ' mein ', ' meine ', ' aus ',
        );
        $english_score = 0;
        $german_score = 0;

        foreach ($english_markers as $marker) {
            if (false !== strpos($sample, $marker)) {
                $english_score++;
            }
        }

        foreach ($german_markers as $marker) {
            if (false !== strpos($sample, $marker)) {
                $german_score++;
            }
        }

        if (preg_match('/\b(i|i m|im|you|your|we|our|please|hello|good day|thanks)\b/i', $sample)) {
            $english_score += 2;
        }

        if ($english_score > $german_score && $english_score >= 1) {
            return 'English';
        }

        if ($german_score > $english_score && $german_score >= 1) {
            return 'Deutsch';
        }

        return null;
    }

    private function detect_explicit_language_request($question, array $history)
    {
        $parts = array(trim((string) $question));

        foreach (array_reverse($history) as $item) {
            if (($item['role'] ?? '') !== 'user') {
                continue;
            }

            $content = trim((string) ($item['content'] ?? ''));
            if ('' === $content) {
                continue;
            }

            $parts[] = $content;
            if (count($parts) >= 3) {
                break;
            }
        }

        $sample = strtolower(implode("\n", array_reverse($parts)));

        $german_patterns = array(
            '/\b(auf deutsch|bitte deutsch|wieder deutsch|nur deutsch|deutsch antworten|antworte deutsch|antworten sie deutsch|sprich deutsch|schreib deutsch)\b/u',
            '/\b(german please|answer in german|reply in german|switch to german|respond in german|in german)\b/u',
            '/\b(deutsch bitte|bitte auf deutsch|zurueck auf deutsch|zurück auf deutsch)\b/u',
        );
        $english_patterns = array(
            '/\b(auf englisch|bitte englisch|wieder englisch|nur englisch|englisch antworten|antworte englisch|sprich englisch|schreib englisch)\b/u',
            '/\b(english please|answer in english|reply in english|switch to english|respond in english|in english)\b/u',
        );

        foreach ($german_patterns as $pattern) {
            if (preg_match($pattern, $sample)) {
                return 'Deutsch';
            }
        }

        foreach ($english_patterns as $pattern) {
            if (preg_match($pattern, $sample)) {
                return 'English';
            }
        }

        return null;
    }

    private function detect_conversation_language($question, array $history)
    {
        $parts = array(trim((string) $question));

        foreach (array_reverse($history) as $item) {
            if (($item['role'] ?? '') !== 'user') {
                continue;
            }

            $content = trim((string) ($item['content'] ?? ''));
            if ('' === $content) {
                continue;
            }

            $parts[] = $content;
            if (count($parts) >= 3) {
                break;
            }
        }

        $sample = strtolower(implode(' ', array_reverse($parts)));
        $english_markers = array(' the ', ' and ', ' what ', ' how ', ' can ', ' do ', ' price ', ' costs ', ' contact ', ' appointment ', ' service ', ' please ');
        $german_markers = array(' der ', ' die ', ' das ', ' und ', ' wie ', ' was ', ' kann ', ' kostet ', ' kontakt ', ' termin ', ' leistung ', ' bitte ');
        $english_score = 0;
        $german_score = 0;

        foreach ($english_markers as $marker) {
            if (false !== strpos(' ' . $sample . ' ', $marker)) {
                $english_score++;
            }
        }

        foreach ($german_markers as $marker) {
            if (false !== strpos(' ' . $sample . ' ', $marker)) {
                $german_score++;
            }
        }

        if (preg_match('/\b(hello|hi|thanks|please|service|price|contact|appointment)\b/i', $sample)) {
            $english_score += 2;
        }

        if (preg_match('/\b(hallo|danke|bitte|leistung|kosten|kontakt|termin)\b/i', $sample)) {
            $german_score += 2;
        }

        return $english_score > $german_score ? 'English' : 'Deutsch';
    }

    private function build_history_text(array $history)
    {
        if (empty($history)) {
            return 'Kein vorheriger Verlauf.';
        }

        $parts = array();
        $history = array_slice($history, -6);
        foreach ($history as $item) {
            $role = ($item['role'] ?? '') === 'assistant' ? 'Assistent' : 'Nutzer';
            $content = trim((string) ($item['content'] ?? ''));
            if ('' === $content) {
                continue;
            }

            $parts[] = $role . ': ' . preg_replace('/\s+/u', ' ', wp_strip_all_tags($content));
        }

        return empty($parts) ? 'Kein vorheriger Verlauf.' : implode("\n", $parts);
    }

    private function extract_text(array $body)
    {
        if (! empty($body['output_text'])) {
            return trim((string) $body['output_text']);
        }

        if (empty($body['output']) || ! is_array($body['output'])) {
            return '';
        }

        foreach ($body['output'] as $item) {
            if (empty($item['content']) || ! is_array($item['content'])) {
                continue;
            }

            foreach ($item['content'] as $content) {
                if (($content['type'] ?? '') === 'output_text' && ! empty($content['text'])) {
                    return trim((string) $content['text']);
                }
            }
        }

        return '';
    }
}
