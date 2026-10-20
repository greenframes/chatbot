<?php

if (! defined('ABSPATH')) {
    exit;
}

class WPCA_Knowledge_Base
{
    private $logs_table_columns = null;

    const OPTION_KEY = 'wpca_settings';
    const TRANSIENT_LAST_SYNC = 'wpca_last_sync_result';

    public function register_settings()
    {
        register_setting('wpca_settings_group', self::OPTION_KEY, array($this, 'sanitize_settings'));
    }

    public function sanitize_settings($input)
    {
        $existing = $this->get_settings();

        return array(
            'enabled' => empty($input['enabled']) ? 0 : 1,
            'openai_api_key' => isset($input['openai_api_key']) ? sanitize_text_field($input['openai_api_key']) : ($existing['openai_api_key'] ?? ''),
            'chat_model' => sanitize_text_field($input['chat_model'] ?? 'gpt-4o-mini'),
            'embedding_model' => sanitize_text_field($input['embedding_model'] ?? 'text-embedding-3-small'),
            'response_language' => sanitize_text_field($input['response_language'] ?? 'auto'),
            'source_website' => esc_url_raw($input['source_website'] ?? home_url('/')),
            'source_urls' => $this->sanitize_source_urls($input['source_urls'] ?? ''),
            'widget_title' => sanitize_text_field($input['widget_title'] ?? 'Aschke Assist'),
            'avatar_url' => esc_url_raw($input['avatar_url'] ?? ''),
            'launcher_eyebrow' => sanitize_text_field($input['launcher_eyebrow'] ?? 'Live-Assistenz'),
            'launcher_label' => sanitize_text_field($input['launcher_label'] ?? 'Jetzt mit unserer Assistenz Asky chatten'),
            'launcher_description' => sanitize_text_field($input['launcher_description'] ?? 'Schnelle Antworten zu Leistungen, Preisen und den naechsten Schritten.'),
            'launcher_cta' => sanitize_text_field($input['launcher_cta'] ?? 'Jetzt starten'),
            'panel_eyebrow' => sanitize_text_field($input['panel_eyebrow'] ?? 'Freundlicher Assistent'),
            'hero_intro' => sanitize_text_field($input['hero_intro'] ?? 'Praegnantes, markenfaehiges Chat-Design mit mehr Aufmerksamkeit.'),
            'brand_badge' => sanitize_text_field($input['brand_badge'] ?? 'Pflegeschule'),
            'footnote' => sanitize_text_field($input['footnote'] ?? 'Funktionen und Inhalte deines bestehenden Chatbots bleiben erhalten.'),
            'behavior_prompt' => sanitize_textarea_field($input['behavior_prompt'] ?? ($existing['behavior_prompt'] ?? $this->get_default_behavior_prompt())),
            'welcome_message' => sanitize_textarea_field($input['welcome_message'] ?? ''),
            'accent_color' => sanitize_hex_color($input['accent_color'] ?? '#F39ca5') ?: '#F39ca5',
            'text_color' => sanitize_hex_color($input['text_color'] ?? '#ffffff') ?: '#ffffff',
            'max_sources' => max(3, min(10, absint($input['max_sources'] ?? 6))),
            'chat_logging' => empty($input['chat_logging']) ? 0 : 1,
            'require_consent' => empty($input['require_consent']) ? 0 : 1,
            'medical_animations' => empty($input['medical_animations']) ? 0 : 1,
            'theme_guardrail' => empty($input['theme_guardrail']) ? 0 : 1,
        );
    }

    public function get_settings()
    {
        $defaults = array(
            'enabled' => 1,
            'openai_api_key' => '',
            'chat_model' => 'gpt-4o-mini',
            'embedding_model' => 'text-embedding-3-small',
            'response_language' => 'auto',
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
            'behavior_prompt' => $this->get_default_behavior_prompt(),
            'welcome_message' => 'Hallo :) Wir helfen dir gern bei Fragen zu unseren Leistungen, Angeboten und Ablaufen weiter.',
            'accent_color' => '#F39ca5',
            'text_color' => '#ffffff',
            'max_sources' => 6,
            'chat_logging' => 1,
            'require_consent' => 1,
            'medical_animations' => 1,
            'theme_guardrail' => 1,
        );

        return wp_parse_args(get_option(self::OPTION_KEY, array()), $defaults);
    }

    public function get_default_behavior_prompt()
    {
        return implode(
            "\n",
            array(
                'Ich bin der Assistent von Aschke Seminare und Qualifizierung.',
                '',
                'Antwortregeln:',
                '- Antworte kurz, klar und freundlich.',
                '- Nutze nur Informationen aus Website und FAQ.',
                '- Wenn eine passende Seite vorhanden ist, nenne direkt den passenden Link.',
                '- Stelle keine unnoetigen Rueckfragen.',
                '- Frage nicht nach national oder international.',
                '- Frage nicht nach dem Standort, ausser es ist fuer die richtige Seite zwingend noetig.',
                '- Bei Fragen zu Ausbildung, Bewerbung, Weiterbildung oder Startterminen verweise direkt auf die passende Seite.',
                '- Bei Startterminen nutze nur die Angaben auf der jeweiligen Ausbildungsseite oder Card.',
                '- Bei Ausbildungs- oder Bewerbungsfragen mit Auslandsbezug erwaehne kurz AschkeCare-Connect als Plattform fuer internationale Bewerbungen.',
                '- Gehe auf Care Connect nur ein, wenn ein klarer Auslandsbezug genannt wird.',
                '- Keine Termine vereinbaren.',
                '- Keine Uploads oder Dateien anbieten.',
                '- Bei Fragen zu Hotel, Gastronomie oder dem Gebaeude verweise auf die Website der Alten Schraubenfabrik.',
                '- Pflegefachmann oder Pflegefachfrau ist die 3-jaehrige Ausbildung zur Fachkraft.',
                '- Antworte in der Ich-Form.',
            )
        );
    }

    public function maybe_create_tables()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $chunks = $this->table_chunks();
        $faqs = $this->table_faqs();
        $logs = $this->table_logs();

        dbDelta(
            "CREATE TABLE {$chunks} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source_type VARCHAR(50) NOT NULL,
                source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                title TEXT NOT NULL,
                url TEXT NOT NULL,
                content LONGTEXT NOT NULL,
                keywords TEXT NULL,
                embedding LONGTEXT NULL,
                checksum VARCHAR(64) NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY source_ref (source_type(20), source_id)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$faqs} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                question TEXT NOT NULL,
                answer LONGTEXT NOT NULL,
                keywords TEXT NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'manual',
                embedding LONGTEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$logs} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_question LONGTEXT NOT NULL,
                assistant_answer LONGTEXT NOT NULL,
                normalized_question TEXT NULL,
                source_snapshot LONGTEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'new',
                promoted_faq_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                confidence_score DECIMAL(6,4) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id)
            ) {$charset};"
        );

        $this->ensure_logs_table_columns();
    }

    public function table_chunks()
    {
        global $wpdb;
        return $wpdb->prefix . 'wpca_chunks';
    }

    public function table_faqs()
    {
        global $wpdb;
        return $wpdb->prefix . 'wpca_faqs';
    }

    public function table_logs()
    {
        global $wpdb;
        return $wpdb->prefix . 'wpca_logs';
    }

    private function ensure_logs_table_columns()
    {
        global $wpdb;

        $logs_table = $this->table_logs();
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$logs_table}", 0);
        if (! is_array($columns)) {
            return;
        }

        if (! in_array('normalized_question', $columns, true)) {
            $wpdb->query("ALTER TABLE {$logs_table} ADD COLUMN normalized_question TEXT NULL AFTER assistant_answer");
        }

        if (! in_array('status', $columns, true)) {
            $wpdb->query("ALTER TABLE {$logs_table} ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'new' AFTER source_snapshot");
        }

        if (! in_array('promoted_faq_id', $columns, true)) {
            $wpdb->query("ALTER TABLE {$logs_table} ADD COLUMN promoted_faq_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER status");
        }
    }

    public function sync_site_content()
    {
        global $wpdb;

        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $this->maybe_create_tables();
        $openai = new WPCA_OpenAI_Client();
        $settings = $this->get_settings();
        $chunks_table = $this->table_chunks();
        $faqs_table = $this->table_faqs();
        $wpdb->query("TRUNCATE TABLE {$chunks_table}");

        $documents = array();
        $seen_urls = array();
        $errors = array();

        foreach ($this->get_configured_source_urls() as $source_url) {
            if (count($documents) >= $this->get_sync_document_limit()) {
                break;
            }

            $document = $this->build_document_from_url($source_url);
            if (is_wp_error($document)) {
                $errors[] = $document->get_error_message();
                continue;
            }

            $documents[] = $document;
            $seen_urls[$document['url']] = true;

            $remaining_slots = max(0, $this->get_sync_document_limit() - count($documents));
            foreach ($this->extract_internal_links_from_url($source_url, min($remaining_slots, $this->get_internal_link_sync_limit())) as $linked_url) {
                if (isset($seen_urls[$linked_url])) {
                    continue;
                }

                $linked_document = $this->build_document_from_url($linked_url);
                if (is_wp_error($linked_document)) {
                    $errors[] = $linked_document->get_error_message();
                    continue;
                }

                $documents[] = $linked_document;
                $seen_urls[$linked_document['url']] = true;

                if (count($documents) >= $this->get_sync_document_limit()) {
                    break 2;
                }
            }
        }

        if (count($documents) < $this->get_sync_document_limit()) {
            foreach ($this->get_published_site_documents($this->get_sync_document_limit() - count($documents)) as $document) {
                if (isset($seen_urls[$document['url']])) {
                    continue;
                }

                $documents[] = $document;
                $seen_urls[$document['url']] = true;

                if (count($documents) >= $this->get_sync_document_limit()) {
                    break;
                }
            }
        }

        if (empty($documents)) {
            $documents = $this->get_published_site_documents($this->get_sync_document_limit());
        }

        $records = array();
        foreach ($documents as $document) {
            $chunks = array_slice($this->split_into_chunks($document['content'], 1200), 0, 3);
            $index = 0;
            foreach ($chunks as $chunk) {
                $records[] = array(
                    'source_type' => $document['source_type'],
                    'source_id' => (int) $document['source_id'],
                    'title' => $document['title'] . ' #' . (++$index),
                    'url' => $document['url'],
                    'content' => $chunk,
                    'keywords' => $this->keywords_from_text($chunk),
                    'checksum' => hash('sha256', $chunk . '|' . $document['url'] . '|' . $index),
                );
            }
        }

        $embeddings = array();
        if (! empty($settings['openai_api_key']) && ! empty($records)) {
            $embeddings = $this->batch_embeddings(
                $openai,
                array_map(
                    function ($record) {
                        return $record['content'];
                    },
                    $records
                )
            );
        }

        foreach ($records as $index => $record) {
            $wpdb->insert(
                    $chunks_table,
                array(
                    'source_type' => $record['source_type'],
                    'source_id' => $record['source_id'],
                    'title' => $record['title'],
                    'url' => $record['url'],
                    'content' => $record['content'],
                    'keywords' => $record['keywords'],
                    'embedding' => ! empty($embeddings[$index]) ? wp_json_encode($embeddings[$index]) : '',
                    'checksum' => $record['checksum'],
                    'updated_at' => current_time('mysql'),
                ),
                array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
        }

        $faqs = $wpdb->get_results("SELECT id, question, answer, keywords, embedding FROM {$faqs_table} WHERE is_active = 1");
        $faq_texts = array_map(
            function ($faq) {
                return $this->normalize_content($faq->question . "\n\n" . $faq->answer . "\n\n" . $faq->keywords);
            },
            $faqs
        );

        foreach ($faqs as $faq_index => $faq) {
            $text = $faq_texts[$faq_index];
            $merged_keywords = $this->merge_keywords($faq->keywords, $text);
            if ($merged_keywords === (string) $faq->keywords) {
                continue;
            }

            $wpdb->update(
                $faqs_table,
                array(
                    'keywords' => $merged_keywords,
                    'embedding' => (string) ($faq->embedding ?? ''),
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => (int) $faq->id),
                array('%s', '%s', '%s'),
                array('%d')
            );
        }

        $result = array(
            'indexed_posts' => count($documents),
            'created_chunks' => count($records),
            'errors' => array_values(array_slice(array_unique(array_filter($errors)), 0, 10)),
            'synced_at' => current_time('mysql'),
        );

        set_transient(self::TRANSIENT_LAST_SYNC, $result, DAY_IN_SECONDS);
        return $result;
    }

    private function get_sync_document_limit()
    {
        return 60;
    }

    private function get_internal_link_sync_limit()
    {
        return 20;
    }

    public function split_into_chunks($text, $limit = 1200)
    {
        $sentences = preg_split('/(?<=[\.\!\?])\s+/u', $text);
        $chunks = array();
        $buffer = '';

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ('' === $sentence) {
                continue;
            }

            if (strlen($buffer . ' ' . $sentence) > $limit && '' !== $buffer) {
                $chunks[] = trim($buffer);
                $buffer = $sentence;
            } else {
                $buffer .= ' ' . $sentence;
            }
        }

        if ('' !== trim($buffer)) {
            $chunks[] = trim($buffer);
        }

        if (empty($chunks)) {
            $chunks[] = mb_substr($text, 0, $limit);
        }

        return $chunks;
    }

    public function sanitize_source_urls($urls)
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $urls);
        $clean = array();

        foreach ($lines as $line) {
            $line = esc_url_raw(trim($line));
            if ('' !== $line) {
                $clean[] = $line;
            }
        }

        return implode("\n", array_values(array_unique($clean)));
    }

    public function get_configured_source_urls()
    {
        $settings = $this->get_settings();
        $urls = array();

        if (! empty($settings['source_website'])) {
            $urls[] = $settings['source_website'];
        }

        if (! empty($settings['source_urls'])) {
            $urls = array_merge($urls, preg_split('/\r\n|\r|\n/', (string) $settings['source_urls']));
        }

        $urls = array_map('trim', $urls);
        $urls = array_filter($urls);
        $urls = array_map('esc_url_raw', $urls);

        return array_values(array_unique($urls));
    }

    public function get_privacy_links()
    {
        $links = array();

        $privacy_page_id = (int) get_option('wp_page_for_privacy_policy');
        if ($privacy_page_id > 0) {
            $links[] = array(
                'title' => get_the_title($privacy_page_id),
                'url' => get_permalink($privacy_page_id),
                'type' => 'privacy',
            );
        }

        foreach ($this->get_configured_source_urls() as $url) {
            $normalized = strtolower((string) $url);
            if (false !== strpos($normalized, 'datenschutz') || false !== strpos($normalized, 'privacy')) {
                $links[] = array(
                    'title' => $this->extract_title_from_html('', $url),
                    'url' => untrailingslashit($url),
                    'type' => 'privacy',
                );
            }
        }

        $seen = array();
        $unique = array();
        foreach ($links as $link) {
            $url = trim((string) ($link['url'] ?? ''));
            if ('' === $url || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $unique[] = $link;
        }

        return array_slice($unique, 0, 3);
    }

    public function get_contact_links()
    {
        $links = array();

        foreach ($this->get_configured_source_urls() as $url) {
            $normalized = strtolower((string) $url);
            if (
                false !== strpos($normalized, 'kontakt') ||
                false !== strpos($normalized, 'contact') ||
                false !== strpos($normalized, 'anfrage') ||
                false !== strpos($normalized, 'request')
            ) {
                $links[] = array(
                    'title' => $this->extract_title_from_html('', $url),
                    'url' => untrailingslashit($url),
                    'type' => 'contact',
                );
            }
        }

        if (empty($links) && ! empty($this->get_settings()['source_website'])) {
            $links[] = array(
                'title' => $this->extract_title_from_html('', $this->get_settings()['source_website']),
                'url' => untrailingslashit($this->get_settings()['source_website']),
                'type' => 'contact',
            );
        }

        $seen = array();
        $unique = array();
        foreach ($links as $link) {
            $url = trim((string) ($link['url'] ?? ''));
            if ('' === $url || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $unique[] = $link;
        }

        return array_slice($unique, 0, 3);
    }

    public function get_reference_routes()
    {
        return array();
    }
    public function find_reference_routes($query, $limit = 3, $group = '')
    {
        $query = $this->normalize_content($query);
        if ('' === $query) {
            return array();
        }

        $search_query = $this->build_dynamic_reference_query($query, $group);
        $results = $this->get_ranked_context_results($search_query);
        $matches = array();
        $seen_urls = array();

        foreach ($results as $result) {
            $url = untrailingslashit(trim((string) ($result['url'] ?? '')));
            if ('' === $url || isset($seen_urls[$url])) {
                continue;
            }

            if (! in_array(($result['type'] ?? ''), array('page', 'contact', 'privacy'), true)) {
                continue;
            }

            $result['url'] = $url;
            $result['debug'] = array_merge((array) ($result['debug'] ?? array()), array('dynamic_reference' => 1));
            $matches[] = $result;
            $seen_urls[$url] = true;

            if (count($matches) >= max(1, (int) $limit)) {
                break;
            }
        }

        return $matches;
    }

    private function build_dynamic_reference_query($query, $group = '')
    {
        $group_terms = array(
            'overview' => 'uebersicht kursangebote angebote leistungen',
            'training' => 'ausbildung weiterbildung kurs seminar pflege',
            'events' => 'veranstaltung event messe termin',
            'career' => 'karriere job stellenangebot arbeiten bewerbung',
            'international' => 'international ausland bewerbung sprache pflege',
            'location' => 'standort adresse kontakt anfahrt',
            'contact' => 'kontakt anfrage formular telefon email',
        );

        $extra = $group_terms[$group] ?? '';
        return trim($query . ' ' . $extra);
    }

    private function score_reference_route($normalized_query, array $query_tokens, array $route)
    {
        $score = 0.0;
        $haystack = $this->normalize_for_match(
            $route['title'] . "\n" . $route['url'] . "\n" . implode(' ', (array) ($route['keywords'] ?? array()))
        );
        $compact_haystack = str_replace(' ', '', $haystack);

        foreach ((array) ($route['keywords'] ?? array()) as $keyword) {
            $keyword = $this->normalize_for_match($keyword);
            if ('' === $keyword) {
                continue;
            }

            $compact_keyword = str_replace(' ', '', $keyword);
            $compact_query = str_replace(' ', '', $normalized_query);

            if ($normalized_query === $keyword || ('' !== $compact_query && $compact_query === $compact_keyword)) {
                $score = max($score, 8.0);
                continue;
            }

            if (
                false !== mb_strpos($normalized_query, $keyword) ||
                false !== mb_strpos($haystack, $normalized_query) ||
                ('' !== $compact_query && false !== mb_strpos($compact_haystack, $compact_query))
            ) {
                $score = max($score, 5.5);
            }
        }

        $score += $this->title_overlap_score($normalized_query, $route['title'], 2.6);
        $score += $this->phrase_score($normalized_query, $haystack, 1.8);
        $score += $this->keyword_score($normalized_query, $haystack) * 2.2;

        if (! empty($query_tokens) && in_array(($route['group'] ?? ''), array('overview', 'contact'), true)) {
            $score += 0.2;
        }

        return $score >= 1.1 ? $score : 0;
    }

    public function build_document_from_url($url)
    {
        $url = esc_url_raw(trim((string) $url));
        if ('' === $url) {
            return new WP_Error('wpca_empty_url', __('Leere Quellen-URL.', 'company-faq-chatbot'));
        }

        if (! $this->is_indexable_content_url($url)) {
            return new WP_Error('wpca_unsupported_url', sprintf(__('Quelle ist keine lesbare Inhaltsseite: %s', 'company-faq-chatbot'), $url));
        }

        if ($this->is_local_site_url($url)) {
            $post = $this->get_published_post_for_url($url);
            if ($post instanceof WP_Post) {
                $result = $this->build_document_from_post($post);
                if (! is_wp_error($result)) {
                    return $result;
                }
            }
        }

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 5,
                'redirection' => 3,
                'sslverify' => false,
                'headers' => array(
                    'Accept' => 'text/html,application/xhtml+xml',
                ),
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error('wpca_fetch_failed', sprintf(__('Quelle konnte nicht geladen werden: %s', 'company-faq-chatbot'), $url));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $html = (string) wp_remote_retrieve_body($response);

        if ($status >= 400 || '' === trim($html)) {
            return new WP_Error('wpca_fetch_failed', sprintf(__('Quelle lieferte keinen nutzbaren Inhalt: %s', 'company-faq-chatbot'), $url));
        }

        $content = $this->extract_text_from_html($html);
        if ('' === $content) {
            return new WP_Error('wpca_fetch_failed', sprintf(__('Kein lesbarer Inhalt gefunden: %s', 'company-faq-chatbot'), $url));
        }

        return array(
            'source_type' => 'url',
            'source_id' => 0,
            'title' => $this->extract_title_from_html($html, $url),
            'url' => untrailingslashit($url),
            'content' => $content,
        );
    }

    public function extract_internal_links_from_url($url, $limit = 18)
    {
        $base_host = wp_parse_url($url, PHP_URL_HOST);
        if (empty($base_host)) {
            return array();
        }

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 4,
                'redirection' => 3,
                'sslverify' => false,
                'headers' => array(
                    'Accept' => 'text/html,application/xhtml+xml',
                ),
            )
        );

        if (is_wp_error($response)) {
            return array();
        }

        $html = (string) wp_remote_retrieve_body($response);
        if ('' === trim($html)) {
            return array();
        }

        preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $matches);
        $links = array();

        foreach ($matches[1] as $href) {
            $href = trim($href);
            if ('' === $href || 0 === strpos($href, '#') || 0 === strpos($href, 'mailto:') || 0 === strpos($href, 'tel:')) {
                continue;
            }

            $absolute = $this->normalize_link_url($href, $url);
            if ('' === $absolute) {
                continue;
            }

            if (strtolower((string) wp_parse_url($absolute, PHP_URL_HOST)) !== strtolower((string) $base_host)) {
                continue;
            }

            if (! $this->is_indexable_content_url($absolute)) {
                continue;
            }

            if ($this->is_local_site_url($absolute) && ! $this->is_root_site_url($absolute) && ! $this->get_published_post_for_url($absolute)) {
                continue;
            }

            if (preg_match('#/(wp-admin|wp-login\.php|wp-json|feed|xmlrpc\.php)#i', (string) wp_parse_url($absolute, PHP_URL_PATH))) {
                continue;
            }

            $links[] = untrailingslashit($absolute);
        }

        $links = array_values(array_unique($links));
        usort($links, function ($left, $right) {
            return $this->score_index_candidate_url($right) <=> $this->score_index_candidate_url($left);
        });

        return array_slice($links, 0, max(0, (int) $limit));
    }

    private function get_published_site_documents($limit)
    {
        $post_types = array_values(
            array_diff(
                get_post_types(array('public' => true, 'publicly_queryable' => true), 'names'),
                array('attachment')
            )
        );

        if (empty($post_types)) {
            return array();
        }

        $posts = get_posts(
            array(
                'post_type' => array_values($post_types),
                'post_status' => 'publish',
                'posts_per_page' => max(1, (int) $limit),
                'orderby' => 'modified',
                'order' => 'DESC',
            )
        );

        $documents = array();
        foreach ($posts as $post) {
            $document = $this->build_document_from_post($post);
            if (! is_wp_error($document)) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    private function build_document_from_post($post)
    {
        if (! ($post instanceof WP_Post) || 'publish' !== get_post_status($post)) {
            return new WP_Error('wpca_unpublished_post', __('Inhalt ist nicht veroeffentlicht.', 'company-faq-chatbot'));
        }

        $post_type_object = get_post_type_object($post->post_type);
        if (! $post_type_object || empty($post_type_object->public)) {
            return new WP_Error('wpca_private_post_type', __('Inhaltstyp ist nicht oeffentlich abfragbar.', 'company-faq-chatbot'));
        }

        $plain = $this->normalize_content(
            implode(
                "\n\n",
                array_filter(
                    array(
                        get_the_title($post),
                        wp_strip_all_tags(get_the_excerpt($post)),
                        wp_strip_all_tags(apply_filters('the_content', $post->post_content)),
                    )
                )
            )
        );

        if ('' === $plain) {
            return new WP_Error('wpca_empty_post', __('Veroeffentlichter Inhalt ist leer.', 'company-faq-chatbot'));
        }

        return array(
            'source_type' => 'post',
            'source_id' => (int) $post->ID,
            'title' => get_the_title($post),
            'url' => untrailingslashit(get_permalink($post)),
            'content' => $plain,
        );
    }

    private function get_published_post_for_url($url)
    {
        $post_id = url_to_postid($url);
        if ($post_id <= 0 && $this->is_root_site_url($url)) {
            $post_id = (int) get_option('page_on_front');
        }

        if ($post_id <= 0) {
            return null;
        }

        $post = get_post($post_id);
        if (! ($post instanceof WP_Post) || 'publish' !== get_post_status($post)) {
            return null;
        }

        return $post;
    }

    private function is_local_site_url($url)
    {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));

        return '' !== $host && '' !== $home_host && $host === $home_host;
    }

    private function is_root_site_url($url)
    {
        $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
        $home_path = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');

        if ('' !== $home_path && 0 === strpos($path, $home_path)) {
            $path = trim(substr($path, strlen($home_path)), '/');
        }

        return '' === $path;
    }

    private function score_index_candidate_url($url)
    {
        $path = $this->normalize_for_match((string) wp_parse_url($url, PHP_URL_PATH));
        $score = 0;

        $high_value_patterns = array(
            'kursangebote',
            'pflegeschule',
            'pflegefachkraft',
            'pflegefachassistenz',
            'bildung-karriere',
            'bildungkarriere',
            'betreuungskraft',
            'praxisanleiter',
            'behandlungspflege',
            'kontakt',
            'arbeiten',
            'veranstaltungen',
        );

        foreach ($high_value_patterns as $pattern) {
            if (false !== mb_strpos($path, $pattern)) {
                $score += 10;
            }
        }

        $low_value_patterns = array(
            'author',
            'category',
            'tag',
            'datenschutz',
            'impressum',
            'feed',
            'wp',
        );

        foreach ($low_value_patterns as $pattern) {
            if (false !== mb_strpos($path, $pattern)) {
                $score -= 8;
            }
        }

        return $score;
    }

    private function is_indexable_content_url($url)
    {
        $path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));
        $query = strtolower((string) wp_parse_url($url, PHP_URL_QUERY));

        if (preg_match('/\.(css|js|json|map|xml|jpg|jpeg|png|gif|webp|svg|ico|pdf|doc|docx|xls|xlsx|ppt|pptx|woff|woff2|ttf|eot|zip|mp4|mp3)$/i', $path)) {
            return false;
        }

        if (preg_match('#/(wp-content|wp-includes|wp-admin)/#i', $path)) {
            return false;
        }

        if (false !== strpos($query, 'ver=') && preg_match('/\.(css|js)$/i', $path)) {
            return false;
        }

        return true;
    }

    public function normalize_link_url($href, $base_url)
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $base_parts = wp_parse_url($base_url);
        if (empty($base_parts['host'])) {
            return '';
        }

        $scheme = $base_parts['scheme'] ?? 'https';
        $host = $base_parts['host'];
        $port = isset($base_parts['port']) ? ':' . $base_parts['port'] : '';

        if (0 === strpos($href, '//')) {
            return $scheme . ':' . $href;
        }

        if (0 === strpos($href, '/')) {
            return $scheme . '://' . $host . $port . $href;
        }

        $path = $base_parts['path'] ?? '/';
        $path = preg_replace('#/[^/]*$#', '/', $path);

        return $scheme . '://' . $host . $port . $path . $href;
    }

    public function extract_title_from_html($html, $fallback_url = '')
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $title = $this->normalize_content($matches[1]);
            if ('' !== $title) {
                return $title;
            }
        }

        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $matches)) {
            $title = $this->normalize_content($matches[1]);
            if ('' !== $title) {
                return $title;
            }
        }

        if ('' !== $fallback_url) {
            $path = trim((string) wp_parse_url($fallback_url, PHP_URL_PATH), '/');
            if ('' !== $path) {
                return ucwords(str_replace(array('-', '_', '/'), ' ', $path));
            }
        }

        return wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    }

    public function extract_text_from_html($html)
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html);
        $html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', ' ', $html);
        $html = preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', ' ', $html);
        $html = preg_replace('/<(header|footer|nav|aside)\b[^>]*>.*?<\/\1>/is', ' ', $html);
        $html = preg_replace('/<\/(p|div|section|article|li|h1|h2|h3|h4|h5|h6)>/i', "$0\n", $html);

        return $this->normalize_content($html);
    }

    public function normalize_content($text)
    {
        $text = wp_strip_all_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim((string) $text);
    }

    public function redact_personal_data($text)
    {
        $text = (string) $text;
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[email]', $text);
        $text = preg_replace('/(?:(?:\+|00)\d{1,3}[\s\/\-]?)?(?:\(?\d{2,5}\)?[\s\/\-]?)?\d(?:[\s\/\-]?\d){5,}/u', '[phone]', $text);
        $text = preg_replace('/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/u', '[iban]', $text);

        return trim($text);
    }

    public function keywords_from_text($text)
    {
        $tokens = preg_split('/[^[:alnum:]\x{00C0}-\x{017F}]+/u', mb_strtolower($this->normalize_content($text)));
        $tokens = array_filter($tokens, function ($token) {
            return mb_strlen($token) > 3;
        });

        return implode(', ', array_slice(array_values(array_unique($tokens)), 0, 40));
    }

    public function batch_embeddings(WPCA_OpenAI_Client $openai, array $texts, $batch_size = 20)
    {
        $vectors = array();
        foreach (array_chunk($texts, max(1, (int) $batch_size)) as $chunk) {
            $result = $openai->create_embeddings($chunk);
            if (is_wp_error($result)) {
                foreach ($chunk as $unused) {
                    $vectors[] = array();
                }
                continue;
            }

            foreach ($result as $vector) {
                $vectors[] = $vector;
            }
        }

        return $vectors;
    }

    public function get_faqs()
    {
        global $wpdb;
        $faqs_table = $this->table_faqs();
        return $wpdb->get_results("SELECT * FROM {$faqs_table} ORDER BY updated_at DESC, created_at DESC");
    }

    public function get_faq($faq_id)
    {
        global $wpdb;
        $faqs_table = $this->table_faqs();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$faqs_table} WHERE id = %d LIMIT 1", absint($faq_id))
        );
    }

    public function save_faq($question, $answer, $faq_id = 0, $manual_keywords = '')
    {
        global $wpdb;

        $settings = $this->get_settings();
        $openai = new WPCA_OpenAI_Client();
        $manual_keywords = sanitize_text_field($manual_keywords);
        $text = $this->normalize_content($question . "\n\n" . $answer . "\n\n" . $manual_keywords);
        $embedding = '';

        if (! empty($settings['openai_api_key'])) {
            $vector = $openai->create_embedding($text);
            if (! is_wp_error($vector)) {
                $embedding = wp_json_encode($vector);
            }
        }

        $data = array(
            'question' => sanitize_text_field($question),
            'answer' => wp_kses_post($answer),
            'keywords' => $this->merge_keywords($manual_keywords, $text),
            'embedding' => $embedding,
            'updated_at' => current_time('mysql'),
        );

        if ($faq_id > 0) {
            return $wpdb->update($this->table_faqs(), $data, array('id' => $faq_id), array('%s', '%s', '%s', '%s', '%s'), array('%d'));
        }

        $data['is_active'] = 1;
        $data['source'] = 'manual';
        $data['created_at'] = current_time('mysql');

        return $wpdb->insert($this->table_faqs(), $data, array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'));
    }

    public function import_faq_file(array $file)
    {
        global $wpdb;

        if (empty($file['tmp_name']) || ! file_exists($file['tmp_name'])) {
            return new WP_Error('wpca_import_missing', __('Keine Datei zum Import gefunden.', 'company-faq-chatbot'));
        }

        $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (! in_array($extension, array('csv', 'xlsx'), true)) {
            return new WP_Error('wpca_import_type', __('Bitte eine CSV- oder XLSX-Datei hochladen.', 'company-faq-chatbot'));
        }

        $rows = 'csv' === $extension ? $this->parse_csv_file($file['tmp_name']) : $this->parse_xlsx_file($file['tmp_name']);
        if (is_wp_error($rows)) {
            return $rows;
        }

        $prepared_rows = $this->prepare_import_rows($rows);
        $valid_rows = $prepared_rows['rows'];
        $skipped = (int) ($prepared_rows['skipped'] ?? 0);

        if (empty($valid_rows)) {
            return array(
                'imported' => 0,
                'skipped' => $skipped,
                'collapsed' => (int) ($prepared_rows['collapsed'] ?? 0),
            );
        }

        $settings = $this->get_settings();
        $embeddings = array();
        if (! empty($settings['openai_api_key'])) {
            $openai = new WPCA_OpenAI_Client();
            $embeddings = $this->batch_embeddings(
                $openai,
                array_map(
                    function ($row) {
                        return $row['text'];
                    },
                    $valid_rows
                ),
                50
            );
        }

        $now = current_time('mysql');
        $imported = 0;
        $faqs_table = $this->table_faqs();

        foreach ($valid_rows as $index => $row) {
            $inserted = $wpdb->insert(
                $faqs_table,
                array(
                    'question' => sanitize_text_field($row['question']),
                    'answer' => wp_kses_post($row['answer']),
                    'keywords' => $this->merge_keywords($row['keywords'], $row['text']),
                    'source' => 'import',
                    'embedding' => ! empty($embeddings[$index]) ? wp_json_encode($embeddings[$index]) : '',
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ),
                array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
            );

            if (false !== $inserted) {
                $imported++;
            } else {
                $skipped++;
            }
        }

        return array(
            'imported' => $imported,
            'skipped' => $skipped,
            'collapsed' => (int) ($prepared_rows['collapsed'] ?? 0),
        );
    }

    private function prepare_import_rows(array $rows)
    {
        $grouped_rows = array();
        $skipped = 0;
        $raw_valid = 0;

        foreach ($rows as $row) {
            $question = trim((string) ($row['question'] ?? ''));
            $answer = trim((string) ($row['answer'] ?? ''));

            if ('' === $question || '' === $answer) {
                $skipped++;
                continue;
            }

            $raw_valid++;
            $normalized_row = array(
                'id' => trim((string) ($row['id'] ?? '')),
                'language' => trim((string) ($row['language'] ?? '')),
                'category' => trim((string) ($row['category'] ?? '')),
                'focus' => trim((string) ($row['focus'] ?? '')),
                'question' => $question,
                'answer' => $answer,
                'keywords' => trim((string) ($row['keywords'] ?? '')),
                'source_url' => trim((string) ($row['source_url'] ?? '')),
            );

            $group_key = $this->build_import_group_key($normalized_row);
            if (! isset($grouped_rows[$group_key])) {
                $grouped_rows[$group_key] = array();
            }

            $grouped_rows[$group_key][] = $normalized_row;
        }

        $valid_rows = array();
        foreach ($grouped_rows as $group_rows) {
            $valid_rows[] = $this->build_consolidated_import_row($group_rows);
        }

        return array(
            'rows' => $valid_rows,
            'skipped' => $skipped,
            'collapsed' => max(0, $raw_valid - count($valid_rows)),
        );
    }

    private function build_import_group_key(array $row)
    {
        $id = $this->normalize_for_match((string) ($row['id'] ?? ''));
        if (preg_match('/^(faq\d+)[\-_]\d+$/i', $id, $matches)) {
            return $matches[1];
        }

        $signature = implode(
            '|',
            array(
                $this->normalize_for_match((string) ($row['answer'] ?? '')),
                $this->normalize_for_match(str_replace(array('_', '-'), ' ', (string) ($row['category'] ?? ''))),
                $this->normalize_for_match(str_replace(array('_', '-'), ' ', (string) ($row['focus'] ?? ''))),
                untrailingslashit(esc_url_raw((string) ($row['source_url'] ?? ''))),
            )
        );

        return md5($signature);
    }

    private function build_consolidated_import_row(array $group_rows)
    {
        $questions = array();
        foreach ($group_rows as $row) {
            $question = trim((string) ($row['question'] ?? ''));
            if ('' === $question) {
                continue;
            }

            $questions[] = $question;
        }

        $cleaned_questions = array_values(
            array_unique(
                array_filter(
                    array_map(array($this, 'strip_import_question_prefix'), $questions)
                )
            )
        );

        $canonical_question = $this->pick_canonical_import_question(! empty($cleaned_questions) ? $cleaned_questions : $questions);
        $answer = trim((string) ($group_rows[0]['answer'] ?? ''));
        $keywords = $this->collect_import_keywords($group_rows, $canonical_question, $cleaned_questions);

        $text_parts = array($canonical_question, $answer);
        if (! empty($cleaned_questions)) {
            $text_parts[] = implode("\n", $cleaned_questions);
        }
        if ('' !== $keywords) {
            $text_parts[] = $keywords;
        }

        return array(
            'question' => $canonical_question,
            'answer' => $answer,
            'keywords' => sanitize_text_field($keywords),
            'text' => $this->normalize_content(implode("\n\n", $text_parts)),
        );
    }

    private function strip_import_question_prefix($question)
    {
        $question = trim((string) $question);
        if ('' === $question) {
            return '';
        }

        $patterns = array(
            '/^kurze frage:\s*/iu',
            '/^faq:\s*/iu',
            '/^was steht auf der website dazu:\s*/iu',
            '/^ich komme aus dem ausland und m(?:oe|ö)chte wissen:\s*/iu',
            '/^ich komme aus deutschland und m(?:oe|ö)chte wissen:\s*/iu',
        );

        foreach ($patterns as $pattern) {
            $question = preg_replace($pattern, '', $question);
        }

        return trim((string) $question);
    }

    private function pick_canonical_import_question(array $questions)
    {
        $questions = array_values(array_filter(array_map('trim', $questions)));
        if (empty($questions)) {
            return '';
        }

        usort($questions, function ($left, $right) {
            $left_weight = mb_strlen($left) + (preg_match('/\?$/u', $left) ? 0 : 4);
            $right_weight = mb_strlen($right) + (preg_match('/\?$/u', $right) ? 0 : 4);

            if ($left_weight === $right_weight) {
                return strnatcasecmp($left, $right);
            }

            return $left_weight <=> $right_weight;
        });

        return $questions[0];
    }

    private function collect_import_keywords(array $group_rows, $canonical_question, array $cleaned_questions)
    {
        $keywords = array();

        foreach ($group_rows as $row) {
            foreach (preg_split('/[,;\n]+/', (string) ($row['keywords'] ?? '')) as $keyword) {
                $keyword = trim((string) $keyword);
                if ('' !== $keyword) {
                    $keywords[] = $keyword;
                }
            }

            foreach (array('category', 'focus') as $field) {
                $value = trim(str_replace(array('_', '-'), ' ', (string) ($row[$field] ?? '')));
                if ('' !== $value) {
                    $keywords[] = $value;
                }
            }

            $source_keywords = $this->extract_keywords_from_source_url($row['source_url'] ?? '');
            if ('' !== $source_keywords) {
                $keywords[] = $source_keywords;
            }
        }

        foreach ($cleaned_questions as $question) {
            if ('' !== $question && $question !== $canonical_question) {
                $keywords[] = $question;
            }
        }

        return implode(', ', array_values(array_unique(array_filter($keywords))));
    }

    private function extract_keywords_from_source_url($url)
    {
        $url = trim((string) $url);
        if ('' === $url) {
            return '';
        }

        $host = preg_replace('/^www\./i', '', (string) wp_parse_url($url, PHP_URL_HOST));
        $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
        $parts = array();

        if ('' !== $host) {
            $parts[] = str_replace(array('.', '-', '_'), ' ', $host);
        }

        if ('' !== $path) {
            $parts[] = str_replace(array('/', '-', '_'), ' ', $path);
        }

        return trim(implode(' ', $parts));
    }

    public function parse_csv_file($path)
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            return new WP_Error('wpca_import_csv', __('CSV-Datei konnte nicht gelesen werden.', 'company-faq-chatbot'));
        }

        $rows = array();
        $header = null;

        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            if (1 === count($data)) {
                $data = str_getcsv($data[0], ',');
            }

            if (null === $header) {
                $header = array_map(array($this, 'normalize_import_header'), $data);
                continue;
            }

            $rows[] = $this->map_import_row($header, $data);
        }

        fclose($handle);

        return $rows;
    }

    public function parse_xlsx_file($path)
    {
        if (! class_exists('ZipArchive')) {
            return new WP_Error('wpca_import_xlsx', __('XLSX-Import ist auf diesem Server nicht verfuegbar.', 'company-faq-chatbot'));
        }

        $zip = new ZipArchive();
        if (true !== $zip->open($path)) {
            return new WP_Error('wpca_import_xlsx', __('XLSX-Datei konnte nicht geoeffnet werden.', 'company-faq-chatbot'));
        }

        $shared_strings = array();
        $shared_xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($shared_xml) {
            $shared_strings = $this->parse_shared_strings($shared_xml);
        }

        $sheet_xml = $this->get_first_xlsx_sheet_xml($zip);
        $zip->close();

        if (! $sheet_xml) {
            return new WP_Error('wpca_import_xlsx', __('Das erste Tabellenblatt konnte nicht gelesen werden.', 'company-faq-chatbot'));
        }

        $xml = @simplexml_load_string($sheet_xml);
        if (! $xml || empty($xml->sheetData->row)) {
            return new WP_Error('wpca_import_xlsx', __('Die XLSX-Daten sind ungueltig.', 'company-faq-chatbot'));
        }

        $rows = array();
        $header = null;
        foreach ($xml->sheetData->row as $row) {
            $cells = array();
            $fallback_index = 0;
            foreach ($row->c as $cell) {
                $cell_ref = (string) $cell['r'];
                $cell_index = '' !== $cell_ref ? $this->xlsx_cell_column_index($cell_ref) : $fallback_index;
                if ($cell_index < 0) {
                    $cell_index = $fallback_index;
                }

                $cells[$cell_index] = trim($this->read_xlsx_cell_value($cell, $shared_strings));
                $fallback_index = $cell_index + 1;
            }

            if (! empty($cells)) {
                ksort($cells);
                $cells = array_replace(array_fill(0, (int) max(array_keys($cells)) + 1, ''), $cells);
            }

            if (null === $header) {
                $header = array_map(array($this, 'normalize_import_header'), $cells);
                continue;
            }

            $rows[] = $this->map_import_row($header, $cells);
        }

        return $rows;
    }

    private function get_first_xlsx_sheet_xml(ZipArchive $zip)
    {
        $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet_xml) {
            return $sheet_xml;
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', (string) $name)) {
                $sheet_xml = $zip->getFromName($name);
                if ($sheet_xml) {
                    return $sheet_xml;
                }
            }
        }

        return false;
    }

    private function read_xlsx_cell_value(SimpleXMLElement $cell, array $shared_strings)
    {
        $type = (string) $cell['t'];

        if ('s' === $type) {
            $index = isset($cell->v) ? (int) $cell->v : -1;
            return $shared_strings[$index] ?? '';
        }

        if ('inlineStr' === $type && isset($cell->is)) {
            return $this->read_xlsx_text_runs($cell->is);
        }

        if (isset($cell->v)) {
            return (string) $cell->v;
        }

        return '';
    }

    private function read_xlsx_text_runs(SimpleXMLElement $item)
    {
        if (isset($item->t)) {
            return (string) $item->t;
        }

        $combined = '';
        foreach ($item->r as $run) {
            $combined .= (string) $run->t;
        }

        return $combined;
    }

    private function xlsx_cell_column_index($cell_ref)
    {
        if (! preg_match('/^([A-Z]+)/i', (string) $cell_ref, $matches)) {
            return -1;
        }

        $letters = strtoupper($matches[1]);
        $index = 0;
        $length = strlen($letters);
        for ($position = 0; $position < $length; $position++) {
            $index = ($index * 26) + (ord($letters[$position]) - 64);
        }

        return $index - 1;
    }

    public function parse_shared_strings($xml_string)
    {
        $xml = @simplexml_load_string($xml_string);
        if (! $xml) {
            return array();
        }

        $strings = array();
        foreach ($xml->si as $item) {
            $strings[] = $this->read_xlsx_text_runs($item);
        }

        return $strings;
    }

    public function normalize_import_header($header)
    {
        $header = sanitize_title((string) $header);
        $map = array(
            'id' => 'id',
            'faq-id' => 'id',
            'faqid' => 'id',
            'language' => 'language',
            'sprache' => 'language',
            'lang' => 'language',
            'category' => 'category',
            'kategorie' => 'category',
            'focus' => 'focus',
            'frage' => 'question',
            'fragen' => 'question',
            'faq-frage' => 'question',
            'question' => 'question',
            'questions' => 'question',
            'titel' => 'question',
            'title' => 'question',
            'antwort' => 'answer',
            'antworten' => 'answer',
            'faq-antwort' => 'answer',
            'answer' => 'answer',
            'answers' => 'answer',
            'loesung' => 'answer',
            'keywords' => 'keywords',
            'keyword' => 'keywords',
            'stichworte' => 'keywords',
            'stichwort' => 'keywords',
            'source-url' => 'source_url',
            'source_url' => 'source_url',
            'sourceurl' => 'source_url',
            'source' => 'source_url',
            'quelle' => 'source_url',
            'url' => 'source_url',
            'link' => 'source_url',
        );

        return $map[$header] ?? $header;
    }

    public function map_import_row(array $header, array $data)
    {
        $row = array();
        foreach ($header as $index => $column) {
            $row[$column] = $data[$index] ?? '';
        }

        return $row;
    }

    public function delete_faq($faq_id)
    {
        global $wpdb;
        return $wpdb->delete($this->table_faqs(), array('id' => (int) $faq_id), array('%d'));
    }

    public function delete_faqs(array $faq_ids)
    {
        global $wpdb;

        $faq_ids = array_values(array_filter(array_map('absint', $faq_ids)));
        if (empty($faq_ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($faq_ids), '%d'));
        $query = $wpdb->prepare("DELETE FROM {$this->table_faqs()} WHERE id IN ({$placeholders})", $faq_ids);

        return (int) $wpdb->query($query);
    }

    public function delete_imported_faqs()
    {
        global $wpdb;

        return (int) $wpdb->delete($this->table_faqs(), array('source' => 'import'), array('%s'));
    }

    public function get_recent_logs($limit = 20)
    {
        global $wpdb;
        $logs_table = $this->table_logs();
        $fetch_limit = max(1, absint($limit));
        $has_status = $this->logs_table_has_column('status');
        $query = $has_status
            ? $wpdb->prepare(
                "SELECT * FROM {$logs_table} WHERE status <> %s ORDER BY created_at DESC LIMIT %d",
                'ignored',
                $fetch_limit
            )
            : $wpdb->prepare(
                "SELECT * FROM {$logs_table} ORDER BY created_at DESC LIMIT %d",
                $fetch_limit
            );
        $logs = $wpdb->get_results($query);

        return array_slice($this->hydrate_logs(is_array($logs) ? $logs : array()), 0, max(1, absint($limit)));
    }

    public function get_log($log_id)
    {
        global $wpdb;
        $logs_table = $this->table_logs();
        $log = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$logs_table} WHERE id = %d LIMIT 1", absint($log_id))
        );

        if (! $log) {
            return null;
        }

        $logs = $this->hydrate_logs(array($log));
        return $logs[0] ?? null;
    }

    public function ignore_log($log_id)
    {
        global $wpdb;

        if (! $this->logs_table_has_column('status')) {
            return false;
        }

        return (bool) $wpdb->update(
            $this->table_logs(),
            array('status' => 'ignored'),
            array('id' => absint($log_id)),
            array('%s'),
            array('%d')
        );
    }

    public function promote_log_to_faq($log_id)
    {
        global $wpdb;

        $log = $this->get_log($log_id);
        if (! $log) {
            return new WP_Error('wpca_log_missing', __('Lernansicht-Eintrag nicht gefunden.', 'company-faq-chatbot'));
        }

        if (empty($log->user_question) || empty($log->assistant_answer)) {
            return new WP_Error('wpca_log_invalid', __('Der Log-Eintrag ist unvollstaendig.', 'company-faq-chatbot'));
        }

        if (($log->status ?? '') === 'promoted' && ! empty($log->promoted_faq_id)) {
            return (int) $log->promoted_faq_id;
        }

        $keywords = implode(', ', (array) ($log->suggested_keywords ?? array()));
        $this->save_faq($log->user_question, $log->assistant_answer, 0, $keywords);
        $faq_id = (int) $wpdb->insert_id;

        if ($faq_id <= 0) {
            return new WP_Error('wpca_promote_failed', __('FAQ konnte nicht erstellt werden.', 'company-faq-chatbot'));
        }

        if ($this->logs_table_has_column('status') && $this->logs_table_has_column('promoted_faq_id')) {
            $wpdb->update(
                $this->table_logs(),
                array(
                    'status' => 'promoted',
                    'promoted_faq_id' => $faq_id,
                ),
                array('id' => (int) $log_id),
                array('%s', '%d'),
                array('%d')
            );
        }

        return $faq_id;
    }

    public function apply_log_keywords_to_best_faq($log_id)
    {
        $log = $this->get_log($log_id);
        if (! $log) {
            return new WP_Error('wpca_log_missing', __('Lernansicht-Eintrag nicht gefunden.', 'company-faq-chatbot'));
        }

        $faq = $this->find_best_matching_manual_faq($log->user_question);
        if (! $faq) {
            return new WP_Error('wpca_faq_missing', __('Keine passende bestehende FAQ fuer die Keyword-Uebernahme gefunden.', 'company-faq-chatbot'));
        }

        $merged_keywords = array_merge(
            preg_split('/[,;\n]+/', (string) ($faq->keywords ?? '')),
            (array) ($log->suggested_keywords ?? array())
        );
        $merged_keywords = array_values(
            array_unique(
                array_filter(
                    array_map(
                        function ($keyword) {
                            return $this->normalize_for_match($keyword);
                        },
                        $merged_keywords
                    )
                )
            )
        );

        $this->save_faq($faq->question, $faq->answer, (int) $faq->id, implode(', ', $merged_keywords));

        global $wpdb;
        if ($this->logs_table_has_column('status')) {
            $wpdb->update(
                $this->table_logs(),
                array('status' => 'keywords'),
                array('id' => (int) $log_id),
                array('%s'),
                array('%d')
            );
        }

        return (int) $faq->id;
    }

    public function find_best_matching_manual_faq($question)
    {
        $normalized_question = $this->normalize_for_match($question);
        $best_faq = null;
        $best_score = 0.0;

        foreach ($this->get_faqs() as $faq) {
            $score = 0.0;
            $score += $this->title_overlap_score($normalized_question, $faq->question, 2.0);
            $score += $this->phrase_score($normalized_question, $faq->question, 2.4);
            $score += $this->keyword_score($normalized_question, $faq->question . ' ' . $faq->keywords) * 2.0;

            if ($score > $best_score) {
                $best_score = $score;
                $best_faq = $faq;
            }
        }

        return $best_score >= 1.4 ? $best_faq : null;
    }

    private function hydrate_logs(array $logs)
    {
        if (empty($logs)) {
            return array();
        }

        $frequency_map = array();
        foreach ($logs as $log) {
            $normalized = $this->normalize_learning_question($log->normalized_question ?: $log->user_question);
            if ('' === $normalized) {
                continue;
            }

            if (! isset($frequency_map[$normalized])) {
                $frequency_map[$normalized] = 0;
            }
            $frequency_map[$normalized]++;
        }

        foreach ($logs as $log) {
            $normalized = $this->normalize_learning_question($log->normalized_question ?: $log->user_question);
            $log->normalized_question = $normalized;
            $log->frequency_count = $frequency_map[$normalized] ?? 1;
            $log->is_frequent = ($log->frequency_count ?? 0) >= 2;
            $log->suggested_keywords = $this->suggest_keywords_for_log($log->user_question, $log->assistant_answer);
            $log->matching_faq = null;
            $log->status = (string) ($log->status ?? 'new');
        }

        return $logs;
    }

    public function suggest_keywords_for_log($question, $answer = '', $limit = 8)
    {
        $text = $this->normalize_for_match($question . "\n" . $answer);
        $tokens = $this->tokenize($text);
        $generic = array(
            'ich', 'sie', 'der', 'die', 'das', 'und', 'oder', 'aber', 'bitte', 'danke',
            'hallo', 'guten', 'tag', 'wie', 'was', 'wo', 'wer', 'wann', 'warum',
            'kann', 'kannst', 'kann ich', 'moechte', 'möchte', 'will', 'brauche',
            'mich', 'mir', 'mein', 'meine', 'euch', 'eure', 'ihre', 'unser', 'unsere',
            'ausbildung', 'weiterbildung', 'bewerbung', 'bewerben', 'infos', 'info',
            'seite', 'link', 'machen', 'gibt', 'welche', 'welcher', 'welches'
        );

        $keywords = array();
        foreach ($tokens as $token) {
            if (mb_strlen($token) < 4 || in_array($token, $generic, true)) {
                continue;
            }

            $keywords[] = $token;
        }

        return array_slice(array_values(array_unique($keywords)), 0, max(1, (int) $limit));
    }

    public function normalize_learning_question($question)
    {
        $question = $this->normalize_for_match($question);
        $question = preg_replace('/\b(wie|was|wo|wer|wann|warum|bitte|ich|mich|mir|moechte|möchte|will|brauche|kann|kannst)\b/u', ' ', $question);
        $question = preg_replace('/\s+/u', ' ', (string) $question);

        return trim((string) $question);
    }

    public function log_chat($question, $answer, array $sources, $confidence)
    {
        global $wpdb;
        $settings = $this->get_settings();

        if (empty($settings['chat_logging'])) {
            return;
        }

        $snapshot = array();
        foreach ($sources as $source) {
            $snapshot[] = array(
                'type' => $source['type'] ?? '',
                'title' => $source['title'] ?? '',
                'url' => $source['url'] ?? '',
                'score' => isset($source['score']) ? round((float) $source['score'], 4) : 0,
            );
        }

        $wpdb->insert(
            $this->table_logs(),
            $this->build_log_insert_data($question, $answer, $snapshot, $confidence),
            $this->build_log_insert_formats()
        );
    }

    private function build_log_insert_data($question, $answer, array $snapshot, $confidence)
    {
        $data = array(
            'user_question' => sanitize_textarea_field($this->redact_personal_data($question)),
            'assistant_answer' => wp_kses_post($this->redact_personal_data(wp_strip_all_tags($answer))),
            'source_snapshot' => wp_json_encode($snapshot),
            'confidence_score' => round((float) $confidence, 4),
            'created_at' => current_time('mysql'),
        );

        if ($this->logs_table_has_column('normalized_question')) {
            $data['normalized_question'] = $this->normalize_learning_question($question);
        }

        if ($this->logs_table_has_column('status')) {
            $data['status'] = 'new';
        }

        if ($this->logs_table_has_column('promoted_faq_id')) {
            $data['promoted_faq_id'] = 0;
        }

        return $data;
    }

    private function build_log_insert_formats()
    {
        $formats = array('%s', '%s');

        if ($this->logs_table_has_column('normalized_question')) {
            $formats[] = '%s';
        }

        $formats[] = '%s';

        if ($this->logs_table_has_column('status')) {
            $formats[] = '%s';
        }

        if ($this->logs_table_has_column('promoted_faq_id')) {
            $formats[] = '%d';
        }

        $formats[] = '%f';
        $formats[] = '%s';

        return $formats;
    }

    private function logs_table_has_column($column)
    {
        global $wpdb;

        if (null === $this->logs_table_columns) {
            $logs_table = $this->table_logs();
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$logs_table}", 0);
            $this->logs_table_columns = is_array($columns) ? $columns : array();
        }

        return in_array($column, $this->logs_table_columns, true);
    }

    public function retrieve_relevant_context($query, $limit = 6)
    {
        $results = $this->get_ranked_context_results($query);

        return array_slice($results, 0, max(1, $limit));
    }

    private function merge_keywords($manual_keywords, $text)
    {
        $keywords = array();

        foreach (preg_split('/[,;\n]+/', (string) $manual_keywords) as $keyword) {
            $keyword = $this->normalize_for_match($keyword);
            if ('' === $keyword) {
                continue;
            }

            $keywords[] = $keyword;
        }

        $keywords = array_merge($keywords, $this->tokenize($this->keywords_from_text($text)));

        return implode(', ', array_values(array_unique(array_filter($keywords))));
    }

    public function debug_relevance($query, $limit = 8)
    {
        $query = $this->normalize_content($query);
        $results = $this->get_ranked_context_results($query);

        return array(
            'query' => $query,
            'confidence' => $this->assess_relevance_confidence($results),
            'results' => array_slice($results, 0, max(1, $limit)),
        );
    }

    public function assess_relevance_confidence(array $sources)
    {
        $best_score = (float) ($sources[0]['score'] ?? 0);
        $second_score = (float) ($sources[1]['score'] ?? 0);
        $gap = max(0, $best_score - $second_score);
        $top_type = (string) ($sources[0]['type'] ?? '');
        $support_count = 0;

        foreach (array_slice($sources, 0, 3) as $source) {
            if ((float) ($source['score'] ?? 0) >= max(1.25, $best_score * 0.55)) {
                $support_count++;
            }
        }

        $has_strong_faq = 'faq' === $top_type && $best_score >= 1.7;
        $level = 'low';
        $reason = 'Zu wenig passende Treffer.';

        if (
            $best_score >= 4.2 ||
            ($has_strong_faq && $gap >= 0.45) ||
            ($best_score >= 3.1 && $support_count >= 2)
        ) {
            $level = 'high';
            $reason = 'Trefferlage ist klar und gut abgestuetzt.';
        } elseif (
            $best_score >= 2.1 ||
            ($best_score >= 1.6 && $gap >= 0.45) ||
            ($has_strong_faq && $best_score >= 1.7)
        ) {
            $level = 'medium';
            $reason = 'Trefferlage ist brauchbar, aber nicht ganz eindeutig.';
        } elseif ($best_score > 0 && $gap < 0.25) {
            $reason = 'Mehrere aehnliche Treffer ohne klaren Favoriten.';
        } elseif ($best_score > 0) {
            $reason = 'Treffer vorhanden, aber zu schwach fuer eine sichere Antwort.';
        }

        return array(
            'level' => $level,
            'best_score' => round($best_score, 4),
            'second_score' => round($second_score, 4),
            'gap' => round($gap, 4),
            'support_count' => $support_count,
            'top_type' => $top_type,
            'should_fallback' => 'low' === $level,
            'reason' => $reason,
        );
    }

    private function get_ranked_context_results($query)
    {
        global $wpdb;

        $query = $this->normalize_content($query);
        if ('' === $query) {
            return array();
        }

        $settings = $this->get_settings();
        $openai = new WPCA_OpenAI_Client();
        $query_vector = null;

        if (! empty($settings['openai_api_key'])) {
            $embedding = $openai->create_embedding($query);
            if (! is_wp_error($embedding)) {
                $query_vector = $embedding;
            }
        }

        $results = array();
        $faqs_table = $this->table_faqs();
        $chunks_table = $this->table_chunks();
        $faqs = $wpdb->get_results("SELECT id, question, answer, keywords, embedding FROM {$faqs_table} WHERE is_active = 1");
        $chunks = $wpdb->get_results("SELECT id, title, url, content, keywords, embedding FROM {$chunks_table}");

        foreach ($faqs as $faq) {
            $results[] = $this->build_faq_result($query, $faq, $query_vector);
        }

        foreach ($chunks as $chunk) {
            $results[] = $this->build_chunk_result($query, $chunk, $query_vector);
        }

        usort($results, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return $results;
    }

    private function build_faq_result($query, $faq, $query_vector = null)
    {
        $breakdown = array(
            'keyword_title' => $this->keyword_score($query, $faq->question) * 3.2,
            'keyword_body' => $this->keyword_score($query, $faq->answer . ' ' . $faq->keywords) * 1.3,
            'phrase_title' => $this->phrase_score($query, $faq->question, 3.6),
            'phrase_body' => $this->phrase_score($query, $faq->answer, 1.4),
            'title_overlap' => $this->title_overlap_score($query, $faq->question, 2.2),
            'base_bonus' => 0.35,
            'embedding' => 0,
        );

        if ($query_vector && ! empty($faq->embedding)) {
            $breakdown['embedding'] = $this->cosine_similarity($query_vector, json_decode($faq->embedding, true)) * 1.7;
        }

        return array(
            'type' => 'faq',
            'id' => (int) $faq->id,
            'title' => $faq->question,
            'url' => '',
            'content' => wp_strip_all_tags($faq->answer),
            'score' => array_sum($breakdown),
            'debug' => $breakdown,
        );
    }

    private function build_chunk_result($query, $chunk, $query_vector = null)
    {
        $breakdown = array(
            'keyword_title' => $this->keyword_score($query, $chunk->title) * 2.4,
            'keyword_body' => $this->keyword_score($query, $chunk->content . ' ' . $chunk->keywords) * 1.0,
            'phrase_title' => $this->phrase_score($query, $chunk->title, 2.6),
            'phrase_body' => $this->phrase_score($query, $chunk->content, 1.0),
            'title_overlap' => $this->title_overlap_score($query, $chunk->title, 1.6),
            'embedding' => 0,
        );

        if ($query_vector && ! empty($chunk->embedding)) {
            $breakdown['embedding'] = $this->cosine_similarity($query_vector, json_decode($chunk->embedding, true)) * 1.4;
        }

        return array(
            'type' => 'page',
            'id' => (int) $chunk->id,
            'title' => $chunk->title,
            'url' => $chunk->url,
            'content' => $chunk->content,
            'score' => array_sum($breakdown),
            'debug' => $breakdown,
        );
    }

    public function phrase_score($query, $document, $weight = 1.0)
    {
        $normalized_query = $this->normalize_for_match($query);
        $normalized_document = $this->normalize_for_match($document);

        if ('' === $normalized_query || '' === $normalized_document) {
            return 0;
        }

        if ($normalized_query === $normalized_document) {
            return 1.8 * $weight;
        }

        if (false !== strpos($normalized_document, $normalized_query)) {
            return 1.2 * $weight;
        }

        $query_tokens = $this->tokenize($normalized_query);
        $document_tokens = $this->tokenize($normalized_document);

        if (count($query_tokens) >= 2 && count($document_tokens) >= 2) {
            $matched = 0;
            $document_bigrams = $this->build_bigrams($document_tokens);

            foreach ($this->build_bigrams($query_tokens) as $query_bigram) {
                foreach ($document_bigrams as $document_bigram) {
                    if ($this->bigram_matches($query_bigram, $document_bigram)) {
                        $matched++;
                        break;
                    }
                }
            }

            if ($matched > 0) {
                return ($matched / max(1, count($query_tokens) - 1)) * $weight;
            }
        }

        return 0;
    }

    public function title_overlap_score($query, $title, $weight = 1.0)
    {
        $query_tokens = $this->tokenize($this->normalize_for_match($query));
        $title_tokens = $this->tokenize($this->normalize_for_match($title));

        if (empty($query_tokens) || empty($title_tokens)) {
            return 0;
        }

        $hits = 0;
        foreach ($query_tokens as $token) {
            foreach ($title_tokens as $title_token) {
                if ($this->tokens_match($token, $title_token)) {
                    $hits++;
                    break;
                }
            }
        }

        return ($hits / max(1, count($query_tokens))) * $weight;
    }

    public function keyword_score($query, $document)
    {
        $query_tokens = $this->tokenize($this->normalize_for_match($query));
        $document_tokens = $this->tokenize($this->normalize_for_match($document));

        if (empty($query_tokens) || empty($document_tokens)) {
            return 0;
        }

        $hits = 0;
        foreach ($query_tokens as $token) {
            foreach ($document_tokens as $document_token) {
                if ($this->tokens_match($token, $document_token)) {
                    $hits++;
                    break;
                }
            }
        }

        return $hits / max(1, count($query_tokens));
    }

    public function tokenize($text)
    {
        $parts = preg_split('/[^[:alnum:]\x{00C0}-\x{017F}]+/u', mb_strtolower($this->normalize_content($text)));
        $parts = array_filter($parts, function ($part) {
            return mb_strlen($part) > 2;
        });

        return array_values(array_unique($parts));
    }

    public function normalize_for_match($text)
    {
        $text = mb_strtolower($this->normalize_content($text));
        $text = str_replace(
            array('ä', 'ö', 'ü', 'ß'),
            array('ae', 'oe', 'ue', 'ss'),
            $text
        );
        $text = preg_replace('/[^[:alnum:]\s]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim((string) $text);
    }

    public function build_bigrams(array $tokens)
    {
        $bigrams = array();
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            $bigrams[] = $tokens[$i] . ' ' . $tokens[$i + 1];
        }

        return $bigrams;
    }

    private function bigram_matches($query_bigram, $document_bigram)
    {
        $query_parts = explode(' ', (string) $query_bigram, 2);
        $document_parts = explode(' ', (string) $document_bigram, 2);

        if (count($query_parts) !== 2 || count($document_parts) !== 2) {
            return false;
        }

        return $this->tokens_match($query_parts[0], $document_parts[0]) &&
            $this->tokens_match($query_parts[1], $document_parts[1]);
    }

    private function tokens_match($query_token, $document_token)
    {
        $query_token = $this->normalize_for_match($query_token);
        $document_token = $this->normalize_for_match($document_token);

        if ('' === $query_token || '' === $document_token) {
            return false;
        }

        if ($query_token === $document_token) {
            return true;
        }

        if ($this->canonical_token($query_token) === $this->canonical_token($document_token)) {
            return true;
        }

        if ($this->has_strong_prefix_match($query_token, $document_token)) {
            return true;
        }

        return $this->is_fuzzy_token_match($query_token, $document_token);
    }

    private function has_strong_prefix_match($left_token, $right_token)
    {
        $left_token = $this->normalize_for_match($left_token);
        $right_token = $this->normalize_for_match($right_token);

        if ('' === $left_token || '' === $right_token || $left_token === $right_token) {
            return false;
        }

        $left_length = strlen($left_token);
        $right_length = strlen($right_token);
        $shorter = $left_length <= $right_length ? $left_token : $right_token;
        $longer = $left_length <= $right_length ? $right_token : $left_token;
        $shorter_length = min($left_length, $right_length);
        $longer_length = max($left_length, $right_length);

        if ($shorter_length < 5 || 0 !== strpos($longer, $shorter)) {
            return false;
        }

        return ($shorter_length / max(1, $longer_length)) >= 0.6;
    }

    private function canonical_token($token)
    {
        static $map = null;

        if (null === $map) {
            $map = array();
            foreach ($this->get_synonym_groups() as $group) {
                $canonical = $group[0];
                foreach ($group as $entry) {
                    $map[$entry] = $canonical;
                }
            }
        }

        $token = $this->normalize_for_match($token);

        return $map[$token] ?? $token;
    }

    private function get_synonym_groups()
    {
        return array(
            array('preis', 'preise', 'kosten', 'kostet', 'gebuehr', 'gebuehren', 'honorar', 'tarif', 'tarife', 'price', 'prices', 'cost', 'costs', 'fee', 'fees'),
            array('termin', 'termine', 'appointment', 'appointments', 'booking', 'book', 'buchen', 'buchung', 'vereinbaren', 'reservieren', 'reservation'),
            array('beratung', 'beraten', 'beratungs', 'consulting', 'consultation', 'consult', 'erstgespraech', 'gespraech', 'erstgesprach', 'beratungsgespraech'),
            array('kontakt', 'kontaktformular', 'anfrage', 'formular', 'contact', 'request', 'reach', 'erreichen', 'email', 'mail', 'telefon', 'phone', 'anrufen'),
            array('oeffnungszeiten', 'oeffnungszeit', 'offnungszeiten', 'zeiten', 'uhrzeit', 'hours', 'opening', 'openinghours'),
            array('adresse', 'anschrift', 'standort', 'location', 'address', 'anfahrt'),
            array('leistung', 'leistungen', 'angebot', 'angebote', 'service', 'services'),
            array('zahlung', 'zahlen', 'payment', 'payments', 'rechnung', 'invoice'),
            array('unterricht', 'unterrichtszeit', 'unterrichtszeiten', 'kurszeit', 'kurszeiten', 'stundenplan'),
            array('krank', 'krankmeldung', 'krank melden', 'abmelden', 'fehlen', 'fehlzeit', 'entschuldigung'),
            array('spaet', 'spät', 'verspaetung', 'verspätung', 'zu spaet', 'zu spät', 'verspaetet', 'verspätet'),
            array('praxisstelle', 'praxis', 'praktikum'),
            array('veranstaltung', 'veranstaltungen', 'event', 'events', 'messe', 'messen'),
            array('berufsfelderkundung', 'berufsfelderkundungstag', 'bfe'),
            array('unterkunft', 'wohnung', 'wohnen', 'accommodation', 'housing'),
            array('aupair', 'au pair'),
            array('stelle', 'stellen', 'stellenangebot', 'stellenangebote', 'job', 'jobs', 'karriere', 'vakanz', 'vakanzen'),
            array('pflegepaedagoge', 'pflegepaedagogin', 'pflegepaedagogen'),
            array('lehrkraft', 'lehrkraefte', 'lehrer', 'lehrerin'),
            array('dozent', 'dozentin', 'dozenten'),
        );
    }

    private function is_fuzzy_token_match($query_token, $document_token)
    {
        if (! function_exists('levenshtein')) {
            return false;
        }

        $query_token = $this->canonical_token($query_token);
        $document_token = $this->canonical_token($document_token);
        $query_length = strlen($query_token);
        $document_length = strlen($document_token);
        $longer_length = max($query_length, $document_length);

        if ($longer_length < 4 || abs($query_length - $document_length) > 2) {
            return false;
        }

        $max_distance = $longer_length >= 8 ? 2 : 1;

        return levenshtein($query_token, $document_token) <= $max_distance;
    }

    public function cosine_similarity($a, $b)
    {
        if (! is_array($a) || ! is_array($b) || count($a) !== count($b) || empty($a)) {
            return 0;
        }

        $dot = 0.0;
        $norm_a = 0.0;
        $norm_b = 0.0;
        foreach ($a as $index => $value) {
            $value = (float) $value;
            $other = (float) $b[$index];
            $dot += $value * $other;
            $norm_a += $value * $value;
            $norm_b += $other * $other;
        }

        if ($norm_a <= 0 || $norm_b <= 0) {
            return 0;
        }

        return $dot / (sqrt($norm_a) * sqrt($norm_b));
    }
}
