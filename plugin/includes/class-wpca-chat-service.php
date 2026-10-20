<?php

if (! defined('ABSPATH')) {
    exit;
}

class WPCA_Chat_Service
{
    private $knowledge_base;
    private $openai;

    public function __construct(WPCA_Knowledge_Base $knowledge_base, WPCA_OpenAI_Client $openai)
    {
        $this->knowledge_base = $knowledge_base;
        $this->openai = $openai;
    }

    public function cari_quip($preferred_language = 'auto', $last_quip = '')
    {
        return $this->openai->create_cari_quip($preferred_language, $last_quip);
    }

    public function answer($question, array $history = array(), $preferred_language = 'auto')
    {
        $question = $this->knowledge_base->redact_personal_data($this->knowledge_base->normalize_content($question));
        $history = $this->sanitize_history($history);
        $user_question = $question;
        $reply_language = $this->detect_reply_language($preferred_language, $question, $history);
        $is_english_reply = 'English' === $reply_language;

        if ('' === $question) {
            return new WP_Error('wpca_empty_question', __('Bitte stelle eine Frage.', 'company-faq-chatbot'));
        }

        if ($this->is_affirmative_reply($question) && $this->assistant_requested_contextual_followup($history)) {
            $question = $this->expand_affirmative_followup_question($history);
        }

        if ($this->is_language_switch_request($question)) {
            $answer = $this->build_language_switch_answer($question);
            $this->knowledge_base->log_chat($user_question, $answer, array(), 1);

            return array(
                'answer' => $this->finalize_answer($answer, array()),
                'sources' => array(),
                'confidence' => 1,
            );
        }

        if ($this->is_greeting_only($question)) {
            $answer = $this->build_greeting_answer($question, $reply_language);
            $this->knowledge_base->log_chat($user_question, $answer, array(), 1);

            return array(
                'answer' => $this->finalize_answer($answer, array()),
                'sources' => array(),
                'confidence' => 1,
            );
        }

        // Vage Opener ohne konkreten Inhalt ("Ich habe eine Frage", "Ich haette eine Frage" etc.)
        $normalized_q = $this->knowledge_base->normalize_for_match((string) $question);
        $vague_openers = array('ich habe eine frage', 'ich hatte eine frage', 'ich haette eine frage', 'habe eine frage', 'hatte eine frage', 'haette eine frage', 'i have a question', 'i have a query', 'quick question');
        if (in_array($normalized_q, $vague_openers, true)) {
            $answer = $this->phrase(
                $reply_language,
                'Sehr gern! Was moechtest du wissen?',
                'Of course! What would you like to know?'
            );
            $this->knowledge_base->log_chat($user_question, $answer, array(), 1);
            return array(
                'answer' => $this->finalize_answer($answer, array()),
                'sources' => array(),
                'confidence' => 1,
            );
        }

        if ($this->is_simple_acknowledgement($question)) {
            $answer = $this->build_acknowledgement_answer($question, $reply_language);
            $this->knowledge_base->log_chat($user_question, $answer, array(), 1);

            return array(
                'answer' => $this->finalize_answer($answer, array()),
                'sources' => array(),
                'confidence' => 1,
            );
        }

        if ($this->is_contact_form_required_request($question, $history)) {
            $sources = $this->knowledge_base->get_contact_links();
            $answer = $is_english_reply
                ? 'I cannot offer or book appointments here, and I cannot forward messages internally. Please use the contact form for that.'
                : 'Termine koennen wir hier nicht anbieten oder vereinbaren. Auch Nachrichten nehmen wir hier nicht entgegen und leiten nichts weiter. Bitte nutze dafuer ausschliesslich das Kontaktformular.';
            if (! empty($sources[0]['url'])) {
                $answer .= ($is_english_reply ? ' The best page is: ' : ' Am passendsten ist diese Seite: ') . $sources[0]['url'];
            }
            $this->knowledge_base->log_chat($user_question, $answer, $sources, 1);

            return array(
                'answer' => $this->finalize_answer($answer, $sources),
                'sources' => $sources,
                'confidence' => 1,
            );
        }

        if ($this->is_personal_data_request($question, $history)) {
            $sources = $this->knowledge_base->get_privacy_links();
            $answer = $is_english_reply
                ? 'For personal data requests or GDPR rights, please use our privacy information or the contact channel listed there so your request can be handled correctly and securely.'
                : 'Bei Anliegen zu personenbezogenen Daten oder Betroffenenrechten gilt bei uns der Datenschutz nach DSGVO. Bitte nutze dafuer unseren Datenschutz-Hinweis oder den dort genannten Kontaktweg, damit dein Anliegen korrekt und sicher bearbeitet werden kann.';
            if (! empty($sources[0]['url'])) {
                $answer .= ($is_english_reply ? ' The best page is: ' : ' Am passendsten ist diese Seite: ') . $sources[0]['url'];
            }
            $this->knowledge_base->log_chat($user_question, $answer, $sources, 1);

            return array(
                'answer' => $this->finalize_answer($answer, $sources),
                'sources' => $sources,
                'confidence' => 1,
            );
        }

        if ($this->is_application_upload_problem_request($question)) {
            $sources = $this->knowledge_base->get_contact_links();
            $answer = $this->build_application_upload_problem_answer($question, $sources, $reply_language);
            $this->knowledge_base->log_chat($user_question, $answer, $sources, 1);

            return array(
                'answer' => $this->finalize_answer($answer, $sources),
                'sources' => $sources,
                'confidence' => 1,
            );
        }

        if ($this->is_apprentio_access_request($question)) {
            $sources = $this->knowledge_base->get_contact_links();
            $answer = $this->build_apprentio_access_answer($sources, $reply_language);
            $this->knowledge_base->log_chat($user_question, $answer, $sources, 1);

            return array(
                'answer' => $this->finalize_answer($answer, $sources),
                'sources' => $sources,
                'confidence' => 1,
            );
        }

        if ($this->is_known_requirement_question($question)) {
            $sources = $this->get_known_requirement_sources($question);
            $answer = $this->build_known_requirement_answer($question, $sources, $reply_language);
            $this->knowledge_base->log_chat($user_question, $answer, $sources, 1);

            return array(
                'answer' => $this->finalize_answer($answer, $sources),
                'sources' => $sources,
                'confidence' => 1,
            );
        }

        $is_context_correction = $this->is_context_correction_reply($question);
        $intent_question = $is_context_correction ? $this->build_retrieval_query($question, $history) : $question;
        $high_value_intent_answer = $this->build_high_value_intent_answer($intent_question, $history, $reply_language);
        if (is_array($high_value_intent_answer)) {
            $answer = $high_value_intent_answer['answer'];
            $sources = $high_value_intent_answer['sources'];
            $confidence = (float) ($high_value_intent_answer['confidence'] ?? 1);

            if (! empty($high_value_intent_answer['skip_links'])) {
                $formatted_answer = $this->format_answer($answer, array());
                $this->knowledge_base->log_chat($user_question, $answer, array(), $confidence);
                return array('answer' => $formatted_answer, 'sources' => array(), 'confidence' => $confidence);
            }

            if ($is_context_correction) {
                $answer = $this->phrase($reply_language, 'Entschuldige, da hatte ich dich falsch verstanden. ', 'Sorry, I misunderstood you there. ') . $answer;
            }
            $this->knowledge_base->log_chat($user_question, $answer, $sources, $confidence);

            return array(
                'answer' => $this->finalize_answer($answer, $sources),
                'sources' => $sources,
                'confidence' => $confidence,
            );
        }

        $current_operational_request = $this->is_operational_student_request($question);
        $settings = $this->knowledge_base->get_settings();
        $retrieval_seed = $current_operational_request ? $question : $this->build_retrieval_query($question, $history);
        $retrieval_query = $this->augment_query_with_aliases($retrieval_seed);
        $is_event_request = $this->is_event_request($retrieval_query);
        $is_job_request = $this->is_job_or_career_request($retrieval_query);
        $is_international_request = $this->is_international_candidate_request($retrieval_query);
        $sources = $this->knowledge_base->retrieve_relevant_context($retrieval_query, (int) $settings['max_sources']);
        $event_reference_sources = $is_event_request ? $this->knowledge_base->find_reference_routes($retrieval_query, 3, 'events') : array();
        if (! empty($event_reference_sources)) {
            $sources = array_merge($event_reference_sources, $sources);
        }
        $career_reference_sources = $is_job_request ? $this->knowledge_base->find_reference_routes($retrieval_query, 3, 'career') : array();
        if (! empty($career_reference_sources)) {
            $sources = array_merge($career_reference_sources, $sources);
        }
        $international_reference_sources = $is_international_request ? $this->knowledge_base->find_reference_routes($retrieval_query, 3, 'international') : array();
        if (! empty($international_reference_sources)) {
            $sources = array_merge($international_reference_sources, $sources);
        }
        $reference_sources = $this->knowledge_base->find_reference_routes($retrieval_query, 3);
        if (! empty($reference_sources)) {
            $sources = array_merge($reference_sources, $sources);
        }
        $sources = $this->filter_sources_for_question($sources, $retrieval_query);
        $confidence = $this->knowledge_base->assess_relevance_confidence($sources);
        $best_score = $confidence['best_score'] ?? 0;
        $prepared_sources = $this->prepare_sources($sources);
        $allow_persona_smalltalk = $this->is_persona_smalltalk_request($question, $history);
        $page_sources = $this->filter_sources_by_type($prepared_sources, array('page', 'contact', 'privacy'));
        $is_training_request = $this->is_training_or_application_request($retrieval_query);
        $is_location_request = $this->is_location_or_school_request($retrieval_query);
        $is_general_application_request = $this->is_general_application_request($retrieval_query);
        $is_operational_request = $current_operational_request || $this->is_operational_student_request($retrieval_query);
        $exact_page_sources = $this->filter_sources_by_focus_tokens($page_sources, $retrieval_query);
        $reference_overview_sources = $this->knowledge_base->find_reference_routes($retrieval_query, 3, 'overview');
        $overview_page_sources = ! empty($reference_overview_sources) ? $reference_overview_sources : $this->pick_training_overview_sources($page_sources);
        $event_raw_sources = $this->pick_event_sources($sources, $retrieval_query);
        $event_display_sources = $this->prepare_sources($event_raw_sources);
        $career_page_sources = $this->pick_job_sources($page_sources, $retrieval_query);
        $international_raw_sources = $this->pick_international_sources($sources, $retrieval_query);
        $international_display_sources = $this->prepare_sources($international_raw_sources);

        if ($is_event_request && ! empty($event_raw_sources)) {
            $event_answer = $this->build_event_answer($retrieval_query, $event_raw_sources, $event_display_sources, $reply_language);
            $this->knowledge_base->log_chat($user_question, $event_answer, $event_raw_sources, $best_score);

            return array(
                'answer' => $this->finalize_answer($event_answer, $event_display_sources),
                'sources' => $event_display_sources,
                'confidence' => $best_score,
            );
        }

        if ($is_international_request && ! empty($international_raw_sources)) {
            $international_answer = $this->build_international_guidance_answer($retrieval_query, $international_raw_sources, $international_display_sources, $reply_language);
            $this->knowledge_base->log_chat($user_question, $international_answer, $international_raw_sources, $best_score);

            return array(
                'answer' => $this->finalize_answer($international_answer, $international_display_sources),
                'sources' => $international_display_sources,
                'confidence' => $best_score,
            );
        }

        if ($is_job_request && ! empty($career_page_sources)) {
            $job_answer = $this->build_job_application_answer($retrieval_query, $career_page_sources, $reply_language);
            $this->knowledge_base->log_chat($user_question, $job_answer, $sources, $best_score);

            return array(
                'answer' => $this->finalize_answer($job_answer, $career_page_sources),
                'sources' => $career_page_sources,
                'confidence' => $best_score,
            );
        }

        if ($this->is_general_training_overview_request($retrieval_query) && ! $is_operational_request && ! empty($page_sources)) {
            $overview_answer = $this->build_training_overview_answer(! empty($overview_page_sources) ? $overview_page_sources : $page_sources, $reply_language);
            $this->knowledge_base->log_chat($user_question, $overview_answer, $sources, $best_score);

            return array(
                'answer' => $this->finalize_answer($overview_answer, ! empty($overview_page_sources) ? $overview_page_sources : $page_sources),
                'sources' => ! empty($overview_page_sources) ? $overview_page_sources : $page_sources,
                'confidence' => $best_score,
            );
        }

        if ($this->assistant_requested_training_focus($history) && $this->is_short_topic_followup($question) && ! empty($page_sources)) {
            $topic_sources = $this->filter_sources_by_focus_tokens($page_sources, $question);
            if (empty($topic_sources)) {
                $topic_sources = ! empty($overview_page_sources) ? $overview_page_sources : $page_sources;
                $topic_answer = $this->build_no_exact_topic_match_answer($question, $topic_sources, $reply_language);
            } else {
                $topic_answer = $this->build_short_topic_followup_answer($question, $topic_sources, $reply_language);
            }
            $this->knowledge_base->log_chat($user_question, $topic_answer, $sources, $best_score);

            return array(
                'answer' => $this->finalize_answer($topic_answer, $topic_sources),
                'sources' => $topic_sources,
                'confidence' => $best_score,
            );
        }

        if ($is_general_application_request && ! empty($page_sources)) {
            $application_sources = ! empty($exact_page_sources) ? $exact_page_sources : $page_sources;
            $application_answer = $this->build_application_guidance_answer($application_sources, $reply_language);
            $this->knowledge_base->log_chat($user_question, $application_answer, $sources, $best_score);

            return array(
                'answer' => $this->finalize_answer($application_answer, $application_sources),
                'sources' => $application_sources,
                'confidence' => $best_score,
            );
        }

        if (
            $is_training_request &&
            $this->is_specific_training_topic_query($retrieval_query) &&
            empty($exact_page_sources) &&
            ! empty($page_sources) &&
            ($this->is_short_topic_followup($question) || $this->is_short_focus_query($question))
        ) {
            $no_match_sources = ! empty($overview_page_sources) ? $overview_page_sources : $page_sources;
            $no_match_answer = $this->build_no_exact_topic_match_answer($question, $no_match_sources, $reply_language);
            $this->knowledge_base->log_chat($user_question, $no_match_answer, $sources, $best_score);

            return array(
                'answer' => $this->finalize_answer($no_match_answer, $no_match_sources),
                'sources' => $no_match_sources,
                'confidence' => $best_score,
            );
        }

        if ($this->assistant_requested_link_confirmation($history) && $this->is_affirmative_reply($question)) {
            $link_sources = ! empty($page_sources) ? $page_sources : $prepared_sources;
            $link_answer = $this->build_link_confirmation_answer($link_sources, $reply_language);
            $this->knowledge_base->log_chat($user_question, $link_answer, $sources, $best_score);

            return array(
                'answer' => $this->finalize_answer($link_answer, $link_sources),
                'sources' => $link_sources,
                'confidence' => $best_score,
            );
        }

        if ($this->assistant_requested_origin_clarification($history) && ($this->is_origin_clarification_answer($question) || $this->is_candidate_profile_reply($question))) {
            $origin_sources = ! empty($page_sources) ? $page_sources : $prepared_sources;
            $origin_answer = $this->build_origin_clarification_answer($question, $origin_sources, $reply_language);
            $this->knowledge_base->log_chat($user_question, $origin_answer, $sources, $best_score);

            return array(
                'answer' => $this->finalize_answer($origin_answer, $origin_sources),
                'sources' => $origin_sources,
                'confidence' => $best_score,
            );
        }

        if ($this->assistant_requested_visit_context($history) && $this->is_visit_context_answer($question)) {
            $visit_sources = ! empty($page_sources) ? $page_sources : $prepared_sources;
            $visit_answer = $this->build_visit_context_answer($question, $visit_sources, $reply_language);
            $this->knowledge_base->log_chat($user_question, $visit_answer, $sources, $best_score);

            return array(
                'answer' => $this->finalize_answer($visit_answer, $visit_sources),
                'sources' => $visit_sources,
                'confidence' => $best_score,
            );
        }

        if ($this->is_navigation_request($question, $history)) {
            $navigation_sources = ! empty($page_sources) ? $page_sources : (! empty($prepared_sources) ? $prepared_sources : $this->knowledge_base->get_contact_links());
            $navigation_answer = $this->build_navigation_answer($navigation_sources, $reply_language);
            $this->knowledge_base->log_chat($user_question, $navigation_answer, $sources, $best_score);

            return array(
                'answer' => $this->finalize_answer($navigation_answer, $navigation_sources),
                'sources' => $navigation_sources,
                'confidence' => $best_score,
            );
        }

        $direct_faq_answer = ($is_english_reply || $allow_persona_smalltalk || (($is_training_request || $is_location_request || $is_job_request) && ! $is_operational_request)) ? null : $this->get_direct_faq_answer($question, $sources, $confidence, $is_operational_request);
        if ($is_training_request && $this->response_contains_origin_loop($direct_faq_answer) && ! $this->question_explicitly_mentions_foreign_context($retrieval_query)) {
            $direct_faq_answer = null;
        }

        if (null !== $direct_faq_answer) {
            $this->knowledge_base->log_chat($user_question, $direct_faq_answer, $sources, $best_score);

            return array(
                'answer' => $this->finalize_answer($direct_faq_answer, $prepared_sources),
                'sources' => $prepared_sources,
                'confidence' => $best_score,
            );
        }

        if ($is_operational_request) {
            $operational_sources = $this->knowledge_base->get_contact_links();
            $operational_answer = $this->build_operational_guidance_answer($question, $operational_sources, $reply_language);
            $this->knowledge_base->log_chat($user_question, $operational_answer, $operational_sources, max($best_score, 1));

            return array(
                'answer' => $this->finalize_answer($operational_answer, $operational_sources),
                'sources' => $operational_sources,
                'confidence' => max($best_score, 1),
            );
        }

        // Fallback nur wenn wirklich keinerlei Quellen vorhanden sind.
        // Wenn indexierter Inhalt oder Referenzrouten gefunden wurden, beantwortet OpenAI
        // die Frage mit dem tatsächlichen Seiteninhalt — der System-Prompt verhindert Halluzination.
        $has_useful_context = ! empty($sources) && ((float) ($sources[0]['score'] ?? 0)) > 0;
        if (! $has_useful_context && ! $allow_persona_smalltalk && ! $this->is_domain_relevant_info_question($retrieval_query)) {
            $fallback_sources = $this->knowledge_base->get_contact_links();
            $fallback = $is_english_reply
                ? 'I do not have any stored information about that. For a specific request, please use the contact form.'
                : 'Dazu liegen mir keine gespeicherten Informationen vor. Fuer konkrete Anfragen nutze bitte das Kontaktformular.';
            if (! empty($fallback_sources[0]['url'])) {
                $fallback .= ($is_english_reply ? ' Contact: ' : ' Kontakt: ') . $fallback_sources[0]['url'];
            }
            $this->knowledge_base->log_chat($user_question, $fallback, $sources, $best_score);

            return array(
                'answer' => $this->finalize_answer($fallback, $fallback_sources),
                'sources' => $fallback_sources,
                'confidence' => $best_score,
            );
        }

        $response = $this->openai->create_chat_response($question, $sources, $history, $preferred_language, $allow_persona_smalltalk);
        if (is_wp_error($response)) {
            return $response;
        }

        if ($is_training_request && $this->response_contains_origin_loop($response) && ! $this->question_explicitly_mentions_foreign_context($retrieval_query)) {
            $response = $this->build_training_guidance_answer($page_sources, $reply_language);
        }

        $this->knowledge_base->log_chat($user_question, $response, $sources, $best_score);

        return array(
            'answer' => $this->finalize_answer($response, $prepared_sources),
            'sources' => $prepared_sources,
            'confidence' => $best_score,
        );
    }

    private function finalize_answer($text, array $sources = array())
    {
        $text = $this->append_relevant_links_to_answer((string) $text, $sources);
        return $this->format_answer($text, $sources);
    }

    private function format_answer($text, array $sources = array())
    {
        $text = $this->strip_source_references((string) $text);
        $sanitized_text = $this->sanitize_response_urls($text, $sources);
        $formatted = wpautop(make_clickable($sanitized_text));
        return wp_kses_post($formatted);
    }

    private function strip_source_references($text)
    {
        // Entferne [Quelle N | ...]-Bloecke die OpenAI aus dem Kontext uebernommen hat
        $text = preg_replace('/\[Quelle\s*\d+[^\]]*\][^\n]*/ui', '', $text);
        // Entferne Zeilen wie "Quelle 1: https://..." oder "1-jaehrige_Ausbildung_... #1: https://..."
        $text = preg_replace('/^[A-Za-z0-9_\-äöüÄÖÜ]+(?:_[A-Za-z0-9_\-äöüÄÖÜ]+)+\s*#\d+\s*:\s*https?:\/\/[^\n]*/mu', '', $text);
        // Entferne "Startseite_2026 #N: https://..." Muster
        $text = preg_replace('/\b\w[\w_]+\s+#\d+\s*:\s*https?:\/\/\S+/u', '', $text);
        // Mehrfache Leerzeilen zusammenfassen
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    private function sanitize_response_urls($text, array $sources = array())
    {
        $allowed_urls = $this->collect_allowed_urls($sources);
        if (empty($allowed_urls)) {
            return $text;
        }

        return preg_replace_callback(
            '~https?://[^\s<>"\'\)\]]+~iu',
            function ($matches) use ($allowed_urls) {
                $raw_url = (string) ($matches[0] ?? '');
                $trimmed_url = rtrim($raw_url, ".,;:!?");
                $suffix = substr($raw_url, strlen($trimmed_url));

                if ($this->is_allowed_response_url($trimmed_url, $allowed_urls)) {
                    return $trimmed_url . $suffix;
                }

                return '';
            },
            $text
        );
    }

    private function collect_allowed_urls(array $sources)
    {
        $urls = array();

        foreach ($sources as $source) {
            $url = trim((string) ($source['url'] ?? ''));
            if ('' !== $url) {
                $urls[] = $url;
            }
        }

        foreach ($this->knowledge_base->get_configured_source_urls() as $url) {
            $url = trim((string) $url);
            if ('' !== $url) {
                $urls[] = $url;
            }
        }

        $settings = $this->knowledge_base->get_settings();
        if (! empty($settings['source_website'])) {
            $urls[] = (string) $settings['source_website'];
        }

        return array_values(array_unique(array_filter(array_map(array($this, 'normalize_allowed_url'), $urls))));
    }

    private function is_allowed_response_url($url, array $allowed_urls)
    {
        $normalized_url = $this->normalize_allowed_url($url);
        if ('' === $normalized_url) {
            return false;
        }

        return in_array($normalized_url, $allowed_urls, true);
    }

    private function normalize_allowed_url($url)
    {
        $url = html_entity_decode(trim((string) $url), ENT_QUOTES);
        if ('' === $url) {
            return '';
        }

        return untrailingslashit($url);
    }

    private function prepare_sources(array $sources)
    {
        $prepared = array();
        $seen_urls = array();

        foreach ($sources as $source) {
            if (isset($source['score']) && ((float) $source['score']) < 0.1) {
                continue;
            }

            $url = trim((string) ($source['url'] ?? ''));
            if ('' === $url || isset($seen_urls[$url]) || ! $this->is_human_facing_source_url($url)) {
                continue;
            }

            $seen_urls[$url] = true;
            $prepared[] = array(
                'title' => $this->clean_source_title((string) ($source['title'] ?? ''), $url),
                'url' => $url,
                'type' => $source['type'],
            );
        }

        return array_slice($prepared, 0, 3);
    }

    private function clean_source_title($title, $url = '')
    {
        // Entferne " #N" Chunk-Suffix (z.B. "Startseite_2026 #3" → "Startseite_2026")
        $title = preg_replace('/\s*#\d+$/', '', trim($title));
        // Ersetze Unterstriche durch Leerzeichen
        $title = str_replace('_', ' ', $title);
        // Entferne Jahreszahl am Ende wenn vorhanden (z.B. "Startseite 2026" → "Startseite")
        $title = preg_replace('/\s+20\d{2}$/', '', $title);
        $title = trim($title);

        // Wenn Titel leer oder zu kurz, aus URL ableiten
        if (mb_strlen($title) < 3 && '' !== $url) {
            $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
            if ('' !== $path) {
                $title = ucwords(str_replace(array('-', '_'), ' ', basename($path)));
            }
        }

        return $title ?: 'Weitere Informationen';
    }

    private function is_human_facing_source_url($url)
    {
        $url = html_entity_decode(trim((string) $url), ENT_QUOTES);
        if ('' === $url) {
            return false;
        }

        $path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));
        $query = strtolower((string) wp_parse_url($url, PHP_URL_QUERY));

        if (preg_match('/\.(css|js|json|map|xml|jpg|jpeg|png|gif|webp|svg|ico|woff|woff2|ttf|eot|mp4|mp3|zip)$/i', $path)) {
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

    private function filter_sources_by_type(array $sources, array $allowed_types)
    {
        return array_values(
            array_filter($sources, function ($source) use ($allowed_types) {
                return in_array((string) ($source['type'] ?? ''), $allowed_types, true);
            })
        );
    }

    private function build_link_confirmation_answer(array $sources, $reply_language = 'Deutsch')
    {
        $sources = array_values(
            array_filter($sources, function ($source) {
                return ! empty($source['url']);
            })
        );

        if (empty($sources)) {
            return $this->phrase($reply_language, 'Gern. Am besten nutze dafuer die passende Seite auf unserer Website.', 'Of course. Please use the matching page on our website.');
        }

        return $this->phrase($reply_language, 'Gern. Hier ist die passende Seite: ', 'Of course. Here is the matching page: ') . $sources[0]['url'];
    }

    private function build_origin_clarification_answer($question, array $sources, $reply_language = 'Deutsch')
    {
        $question = mb_strtolower((string) $question);
        $sources = array_values(
            array_filter($sources, function ($source) {
                return ! empty($source['url']);
            })
        );

        if ($this->is_candidate_profile_reply($question)) {
            if (! empty($sources[0]['url'])) {
                return $this->phrase($reply_language, 'Verstanden. Fuer internationale Bewerbungen oder einen Au-pair-Hintergrund findest du die passenden Informationen hier: ', 'Understood. For international applications or an au pair background, the matching information is here: ') . $sources[0]['url'];
            }

            return $this->phrase($reply_language, 'Verstanden. Fuer internationale Bewerbungen oder einen Au-pair-Hintergrund nutze bitte die passende Informationsseite auf unserer Website.', 'Understood. For international applications or an au pair background, please use the matching information page on our website.');
        }

        if (false !== mb_strpos($question, 'deutschland') || false !== mb_strpos($question, 'national')) {
            if (! empty($sources[0]['url'])) {
                return $this->phrase($reply_language, 'Alles klar, dann ist fuer dich der regulaere Weg in Deutschland passend. Alle Details zur Ausbildung und Bewerbung findest du hier: ', 'All right, then the regular route in Germany is the right one. You can find the details about training and application here: ') . $sources[0]['url'];
            }

            return $this->phrase($reply_language, 'Alles klar, dann ist fuer dich der regulaere Weg in Deutschland passend. Die passenden Details zur Ausbildung und Bewerbung findest du auf unserer Website.', 'All right, then the regular route in Germany is the right one. You can find the matching details about training and application on our website.');
        }

        if (! empty($sources[0]['url'])) {
            return $this->phrase($reply_language, 'Verstanden. Wenn deine Bewerbung oder Vorbildung aus dem Ausland kommt, findest du die passenden Informationen hier: ', 'Understood. If your application or previous qualification comes from abroad, the matching information is here: ') . $sources[0]['url'];
        }

        return $this->phrase($reply_language, 'Verstanden. Wenn deine Bewerbung oder Vorbildung aus dem Ausland kommt, nutze bitte die passende Informationsseite auf unserer Website.', 'Understood. If your application or previous qualification comes from abroad, please use the matching information page on our website.');
    }

    private function build_visit_context_answer($question, array $sources, $reply_language = 'Deutsch')
    {
        if ('English' === $reply_language) {
            $contact_sources = $this->knowledge_base->get_contact_links();
            if (! empty($contact_sources[0]['url'])) {
                return 'For organisational questions about lessons, practical placements or appointments, please use the contact form: ' . $contact_sources[0]['url'];
            }

            return 'For organisational questions about lessons, practical placements or appointments, please use the contact page on our website.';
        }

        $question = mb_strtolower((string) $question);
        $location_sources = $this->knowledge_base->find_reference_routes('standort hagen pflegeschule', 2, 'location');
        $overview_sources = $this->knowledge_base->find_reference_routes('kursangebote pflegeschule', 2, 'overview');
        $contact_sources = $this->knowledge_base->get_contact_links();

        if (false !== mb_strpos($question, 'unterricht')) {
            $parts = array('Zum Unterricht kommst du zur Pflegeschule am Standort Hagen.');
            if (! empty($location_sources[0]['url'])) {
                $parts[] = 'Den Standort findest du hier: ' . $location_sources[0]['url'];
            }
            if (! empty($overview_sources[0]['url'])) {
                $parts[] = 'Den Ueberblick zu den passenden Ausbildungsseiten findest du hier: ' . $overview_sources[0]['url'];
            }

            return implode(' ', $parts);
        }

        if (false !== mb_strpos($question, 'praxisstelle')) {
            $parts = array('Bei Fragen zur Praxisstelle nutze bitte die passende Ausbildungsseite und bei organisatorischen Themen das Kontaktformular.');
            if (! empty($overview_sources[0]['url'])) {
                $parts[] = 'Der Ueberblick zur Pflegeschule ist hier: ' . $overview_sources[0]['url'];
            }
            if (! empty($contact_sources[0]['url'])) {
                $parts[] = 'Den Kontakt findest du hier: ' . $contact_sources[0]['url'];
            }

            return implode(' ', $parts);
        }

        if (false !== mb_strpos($question, 'beratung')) {
            $parts = array('Einen Beratungstermin kann ich nicht direkt vereinbaren. Bitte nutze dafuer das Kontaktformular.');
            if (! empty($contact_sources[0]['url'])) {
                $parts[] = 'Hier findest du den Kontakt: ' . $contact_sources[0]['url'];
            }

            return implode(' ', $parts);
        }

        if (! empty($contact_sources[0]['url'])) {
            return 'Fuer organisatorische Rueckfragen nutze bitte das Kontaktformular: ' . $contact_sources[0]['url'];
        }

        return 'Fuer organisatorische Rueckfragen nutze bitte die passende Kontaktseite auf unserer Website.';
    }

    private function build_training_guidance_answer(array $sources, $reply_language = 'Deutsch')
    {
        $sources = array_values(
            array_filter($sources, function ($source) {
                return ! empty($source['url']);
            })
        );

        if (empty($sources)) {
            return $this->phrase($reply_language, 'Ich zeige dir gern die passende Ausbildungs- oder Bewerbungsseite. Wenn du mir die genaue Ausbildung oder Weiterbildung nennst, verlinke ich dir direkt die richtige Seite.', 'I can point you to the right training or application page. If you tell me the exact programme, I can link the right page directly.');
        }

        if (1 === count($sources)) {
            return $this->phrase($reply_language, 'Die passende Seite findest du hier: ', 'You can find the matching page here: ') . $sources[0]['url'];
        }

        return $this->phrase($reply_language, 'Die passendsten Seiten findest du hier: ', 'The most relevant pages are here: ') . $sources[0]['url'] . $this->phrase($reply_language, ' und ', ' and ') . $sources[1]['url'];
    }

    private function build_application_guidance_answer(array $sources, $reply_language = 'Deutsch')
    {
        $sources = array_values(
            array_filter($sources, function ($source) {
                return ! empty($source['url']);
            })
        );

        if (empty($sources)) {
            return $this->phrase($reply_language, 'Ich zeige dir gern die passende Bewerbungsseite. Wenn du mir die genaue Ausbildung oder Weiterbildung nennst, verlinke ich dir direkt die richtige Seite.', 'I can point you to the right application page. If you tell me the exact training or course, I can link the right page directly.');
        }

        if (1 === count($sources)) {
            return $this->phrase($reply_language, 'Die passende Seite fuer Bewerbung und weitere Infos findest du hier: ', 'You can find the matching page for application and further information here: ') . $sources[0]['url'];
        }

        return $this->phrase($reply_language, 'Die passendsten Seiten fuer Bewerbung und weitere Infos findest du hier: ', 'The most relevant pages for application and further information are here: ') . $sources[0]['url'] . $this->phrase($reply_language, ' und ', ' and ') . $sources[1]['url'];
    }

    private function build_job_application_answer($question, array $sources, $reply_language = 'Deutsch')
    {
        $sources = array_values(
            array_filter($sources, function ($source) {
                return ! empty($source['url']);
            })
        );
        $role_label = 'fuer eine Stelle bei uns';
        $english_role_label = 'for a position with us';

        if ($this->query_mentions_pedagogy_role($question)) {
            $role_label = 'als Pflegepaedagogin oder Lehrkraft';
            $english_role_label = 'as a nursing educator or teacher';
        } elseif ($this->query_mentions_dozent_role($question)) {
            $role_label = 'als Dozentin oder Dozent';
            $english_role_label = 'as a lecturer';
        }

        if ('English' === $reply_language) {
            if (empty($sources)) {
                return 'If you would like to apply ' . $english_role_label . ', please use the jobs page on our website.';
            }

            if (1 === count($sources)) {
                return 'If you would like to apply ' . $english_role_label . ', you can find the matching job page here: ' . $sources[0]['url'];
            }

            return 'If you would like to apply ' . $english_role_label . ', these pages are the best match: ' . $sources[0]['url'] . ' and ' . $sources[1]['url'];
        }

        if (empty($sources)) {
            return 'Wenn du dich ' . $role_label . ' bewerben moechtest, nutze bitte unsere Stellenangebote auf der Website.';
        }

        if (1 === count($sources)) {
            return 'Wenn du dich ' . $role_label . ' bewerben moechtest, findest du die passende Stellenseite hier: ' . $sources[0]['url'];
        }

        return 'Wenn du dich ' . $role_label . ' bewerben moechtest, sind diese Seiten am passendsten: ' . $sources[0]['url'] . ' und ' . $sources[1]['url'];
    }

    private function build_event_answer($question, array $raw_sources, array $display_sources = array(), $reply_language = 'Deutsch')
    {
        $faq_source = $this->pick_event_faq_source($question, $raw_sources);
        if ('English' !== $reply_language && ! $this->is_generic_event_overview_request($question) && is_array($faq_source)) {
            $faq_answer = trim((string) ($faq_source['content'] ?? ''));
            if ('' !== $faq_answer) {
                return $faq_answer;
            }
        }

        $display_sources = ! empty($display_sources) ? $display_sources : $this->prepare_sources($raw_sources);
        $display_sources = array_values(
            array_filter($display_sources, function ($source) {
                return ! empty($source['url']);
            })
        );

        $event_faq_summaries = $this->collect_event_faq_summaries($raw_sources);
        if ('English' !== $reply_language && $this->is_generic_event_overview_request($question) && ! empty($event_faq_summaries)) {
            $answer = 'Zu den Veranstaltungen habe ich diese Hinweise gefunden: ' . implode(' ', array_slice($event_faq_summaries, 0, 3));
            if (! empty($display_sources[0]['url'])) {
                $answer .= ' Aktuelle Details findest du hier: ' . $display_sources[0]['url'];
            }

            return $answer;
        }

        if ($this->query_mentions_bfe($question)) {
            if (! empty($display_sources[0]['url'])) {
                return $this->phrase($reply_language, 'Die aktuellen Infos zum Berufsfelderkundungstag findest du hier: ', 'Current information about the career exploration day is here: ') . $display_sources[0]['url'];
            }

            return $this->phrase($reply_language, 'Die aktuellen Infos zum Berufsfelderkundungstag findest du auf unserer Veranstaltungsseite.', 'Current information about the career exploration day is available on our events page.');
        }

        if (! empty($display_sources[0]['url'])) {
            return $this->phrase($reply_language, 'Den aktuellen Ueberblick zu unseren Veranstaltungen findest du hier: ', 'You can find the current event overview here: ') . $display_sources[0]['url'];
        }

        if ('English' !== $reply_language && is_array($faq_source)) {
            $faq_answer = trim((string) ($faq_source['content'] ?? ''));
            if ('' !== $faq_answer) {
                return $faq_answer;
            }
        }

        return $this->phrase($reply_language, 'Aktuelle Veranstaltungen und Termine findest du auf unserer Veranstaltungsseite.', 'Current events and dates are available on our events page.');
    }

    private function collect_event_faq_summaries(array $sources)
    {
        $summaries = array();

        foreach ($sources as $source) {
            if (! is_array($source) || (($source['type'] ?? '') !== 'faq') || ! $this->is_event_related_source($source)) {
                continue;
            }

            $content = trim((string) ($source['content'] ?? ''));
            if ('' === $content || isset($summaries[$content])) {
                continue;
            }

            $summaries[$content] = $content;
        }

        return array_values($summaries);
    }

    private function build_training_overview_answer(array $sources, $reply_language = 'Deutsch')
    {
        $sources = array_values(
            array_filter($sources, function ($source) {
                return ! empty($source['url']);
            })
        );

        if (empty($sources)) {
            return $this->phrase($reply_language, 'Ich zeige dir gern die passende Weiterbildungsseite. Wenn du mir den genauen Bereich nennst, verlinke ich dir direkt die richtige Seite.', 'I can point you to the right further training page. If you tell me the exact area, I can link the right page directly.');
        }

        if (1 === count($sources)) {
            return $this->phrase($reply_language, 'Die passende Seite zu den Weiterbildungen findest du hier: ', 'You can find the matching further training page here: ') . $sources[0]['url'];
        }

        return $this->phrase($reply_language, 'Die passenden Seiten zu den Weiterbildungen findest du hier: ', 'The matching further training pages are here: ') . $sources[0]['url'] . $this->phrase($reply_language, ' und ', ' and ') . $sources[1]['url'];
    }

    private function build_short_topic_followup_answer($question, array $sources, $reply_language = 'Deutsch')
    {
        $topic = trim((string) $question);
        $sources = array_values(
            array_filter($sources, function ($source) {
                return ! empty($source['url']);
            })
        );

        if (empty($sources)) {
            return $this->phrase($reply_language, 'Zu ' . $topic . ' zeige ich dir gern die passende Seite, sobald ich dazu einen sicheren Treffer habe.', 'For ' . $topic . ', I can show you the matching page as soon as I have a reliable match.');
        }

        return $this->phrase($reply_language, 'Zu ' . $topic . ' findest du die passende Seite hier: ', 'For ' . $topic . ', you can find the matching page here: ') . $sources[0]['url'];
    }

    private function build_no_exact_topic_match_answer($question, array $sources, $reply_language = 'Deutsch')
    {
        $topic = trim((string) $question);
        $sources = array_values(
            array_filter($sources, function ($source) {
                return ! empty($source['url']);
            })
        );

        if (empty($sources)) {
            return $this->phrase($reply_language, 'Zu ' . $topic . ' finde ich auf unseren Seiten gerade keinen eindeutigen Eintrag.', 'I cannot find a clear entry for ' . $topic . ' on our pages right now.');
        }

        return $this->phrase($reply_language, 'Zu ' . $topic . ' finde ich auf unseren Seiten gerade keinen eindeutigen Eintrag. Am ehesten passt diese Uebersichtsseite: ', 'I cannot find a clear entry for ' . $topic . ' on our pages right now. This overview page is the closest match: ') . $sources[0]['url'];
    }

    private function filter_sources_for_question(array $sources, $query)
    {
        if (empty($sources)) {
            return $sources;
        }

        $filtered_sources = array_values(
            array_filter($sources, function ($source) use ($query) {
                return ! $this->is_off_topic_source_for_query($source, $query);
            })
        );

        if (! empty($filtered_sources)) {
            $sources = $filtered_sources;
        }

        if (! $this->question_explicitly_mentions_foreign_context($query) && ! $this->question_may_need_international_support($query)) {
            $non_foreign_sources = array_values(
                array_filter($sources, function ($source) {
                    return ! $this->is_foreign_specific_source($source);
                })
            );

            if (! empty($non_foreign_sources)) {
                $sources = $non_foreign_sources;
            }
        }

        // Focus-Token-Filter: wenn kein Match, trotzdem alle vorhandenen Quellen behalten
        // (nie komplett leeren — lieber OpenAI mit allgemeinen Quellen als gar keinen Kontext)
        $focus_matched_sources = $this->filter_sources_by_focus_tokens($sources, $query);
        if (! empty($focus_matched_sources)) {
            $sources = $focus_matched_sources;
        }

        return $sources;
    }

    private function filter_sources_by_focus_tokens(array $sources, $query)
    {
        $tokens = $this->extract_focus_tokens($query);
        if (empty($tokens)) {
            return array();
        }

        $matches = array();
        foreach ($sources as $source) {
            if ($this->source_matches_focus_tokens($source, $tokens)) {
                $matches[] = $source;
            }
        }

        return $matches;
    }

    private function extract_focus_tokens($query)
    {
        $query = (string) $query;
        $tokens = array_values(
            array_filter(
                $this->knowledge_base->tokenize($query),
                function ($token) {
                    return ! $this->is_generic_focus_token($token);
                }
            )
        );

        if (! empty($tokens)) {
            return $tokens;
        }

        if ($this->is_short_topic_followup($query) || $this->is_short_focus_query($query)) {
            return $this->knowledge_base->tokenize($query);
        }

        return array();
    }

    private function is_generic_focus_token($token)
    {
        $token = $this->knowledge_base->normalize_for_match($token);
        $generic_tokens = array(
            'wie',
            'lange',
            'dauer',
            'dauert',
            'was',
            'welche',
            'welcher',
            'welches',
            'gibt',
            'machen',
            'bitte',
            'info',
            'infos',
            'ausbildung',
            'weiterbildung',
            'bewerbung',
            'bewerben',
            'kurs',
            'kurse',
            'seminar',
            'seminare',
            'qualifizierung',
            'pflege',
            'schule',
            'seite',
            'link',
            'standort',
            'adresse',
            'angebot',
            'angeboten',
            'hier',
            'dort',
        );

        return '' === $token || in_array($token, $generic_tokens, true);
    }

    private function pick_training_overview_sources(array $sources)
    {
        $matches = array_values(
            array_filter($sources, function ($source) {
                return $this->source_contains_patterns(
                    $source,
                    array(
                        'kursangebote',
                        'fortbildungen',
                        'weiterbild',
                    )
                );
            })
        );

        return ! empty($matches) ? $matches : array();
    }

    private function pick_event_sources(array $sources, $query)
    {
        $event_sources = array_values(
            array_filter($sources, function ($source) {
                return $this->is_event_related_source($source);
            })
        );

        if (empty($event_sources)) {
            return array();
        }

        $focused_sources = $this->filter_sources_by_focus_tokens($event_sources, $query);
        if (! empty($focused_sources)) {
            $event_sources = $focused_sources;
        }

        usort($event_sources, function ($left, $right) use ($query) {
            $left_score = $this->score_event_source_for_query($left, $query);
            $right_score = $this->score_event_source_for_query($right, $query);

            if ($left_score === $right_score) {
                return 0;
            }

            return ($left_score > $right_score) ? -1 : 1;
        });

        return array_slice($event_sources, 0, 5);
    }

    private function is_event_related_source($source)
    {
        return $this->source_contains_patterns(
            $source,
            array(
                'unsere veranstaltungen',
                'veranstaltung',
                'veranstaltungen',
                'berufsfelderkundung',
                'berufsfelderkundungstag',
                'bfe',
                'ausbildungsmesse',
                'berufsbildungsmesse',
                'messe',
                'messen',
                'offener tag',
                'tag der pflege',
            )
        );
    }

    private function score_event_source_for_query(array $source, $query)
    {
        $haystack = implode(
            "\n",
            array(
                (string) ($source['title'] ?? ''),
                (string) ($source['url'] ?? ''),
                (string) ($source['content'] ?? ''),
            )
        );
        $score = $this->knowledge_base->phrase_score($query, $haystack, 2.4)
            + ($this->knowledge_base->keyword_score($query, $haystack) * 1.9)
            + ((float) ($source['score'] ?? 0) * 0.25);

        if ($this->query_mentions_bfe($query) && $this->source_contains_patterns($source, array('berufsfelderkundung', 'berufsfelderkundungstag', 'bfe'))) {
            $score += 3.0;
        }

        if ($this->is_generic_event_overview_request($query) && $this->source_contains_patterns($source, array('unsere veranstaltungen', 'veranstaltungen'))) {
            $score += 2.0;
        }

        return $score;
    }

    private function pick_event_faq_source($question, array $sources)
    {
        $faq_sources = array_values(
            array_filter($sources, function ($source) {
                return is_array($source) && (($source['type'] ?? '') === 'faq') && $this->is_event_related_source($source);
            })
        );

        if (empty($faq_sources)) {
            return null;
        }

        usort($faq_sources, function ($left, $right) use ($question) {
            $left_score = $this->score_event_source_for_query($left, $question);
            $right_score = $this->score_event_source_for_query($right, $question);

            if ($left_score === $right_score) {
                return 0;
            }

            return ($left_score > $right_score) ? -1 : 1;
        });

        return $faq_sources[0];
    }

    private function query_mentions_bfe($text)
    {
        return $this->text_matches_patterns(
            $text,
            array('berufsfelderkundung', 'berufsfelderkundungstag', 'bfe')
        );
    }

    private function is_generic_event_overview_request($text)
    {
        $patterns = array(
            'veranstaltung',
            'veranstaltungen',
            'event',
            'events',
            'naechste veranstaltungen',
            'welche veranstaltungen',
            'was sind die naechsten veranstaltungen',
        );

        if ($this->query_mentions_bfe($text)) {
            return false;
        }

        return $this->text_matches_patterns($text, $patterns);
    }

    private function pick_job_sources(array $sources, $query)
    {
        $job_sources = array_values(
            array_filter($sources, function ($source) {
                return $this->is_job_related_source($source);
            })
        );

        if (empty($job_sources)) {
            return array();
        }

        $focused_sources = $this->filter_sources_by_focus_tokens($job_sources, $query);
        if (! empty($focused_sources)) {
            $job_sources = $focused_sources;
        }

        usort($job_sources, function ($left, $right) use ($query) {
            $left_score = $this->score_job_source_for_query($left, $query);
            $right_score = $this->score_job_source_for_query($right, $query);

            if ($left_score === $right_score) {
                return 0;
            }

            return ($left_score > $right_score) ? -1 : 1;
        });

        return array_slice($job_sources, 0, 3);
    }

    private function is_job_related_source($source)
    {
        return $this->source_contains_patterns(
            $source,
            array(
                'stellenangebot',
                'stellenangebote',
                'arbeiten',
                'lehrkraft',
                'hauptamtliche lehrkraft',
                'pflegepaedagog',
                'dozent',
                'p 8908',
            )
        );
    }

    private function score_job_source_for_query(array $source, $query)
    {
        $haystack = implode(
            "\n",
            array(
                (string) ($source['title'] ?? ''),
                (string) ($source['url'] ?? ''),
                (string) ($source['content'] ?? ''),
            )
        );
        $score = $this->knowledge_base->phrase_score($query, $haystack, 2.2)
            + ($this->knowledge_base->keyword_score($query, $haystack) * 1.8);

        if ($this->query_mentions_pedagogy_role($query) && $this->source_contains_patterns($source, array('pflegepaedagog', 'lehrkraft', 'hauptamtliche lehrkraft'))) {
            $score += 2.6;
        }

        if ($this->query_mentions_dozent_role($query) && $this->source_contains_patterns($source, array('dozent', 'p 8908'))) {
            $score += 2.6;
        }

        if ($this->source_contains_patterns($source, array('stellenangebote', 'arbeiten'))) {
            $score += 1.0;
        }

        return $score;
    }

    private function query_mentions_pedagogy_role($text)
    {
        $patterns = array('pflegepaedagogin', 'pflegepaedagoge', 'pflegepaedagogen', 'lehrkraft', 'lehrkraefte', 'lehrer', 'lehrerin');

        return $this->text_matches_patterns($text, $patterns);
    }

    private function query_mentions_dozent_role($text)
    {
        $patterns = array('dozent', 'dozentin', 'dozenten');

        return $this->text_matches_patterns($text, $patterns);
    }

    private function build_international_guidance_answer($question, array $raw_sources, array $display_sources = array(), $reply_language = 'Deutsch')
    {
        $faq_source = $this->pick_international_faq_source($question, $raw_sources);
        $wants_accommodation = $this->text_matches_patterns($question, array('unterkunft', 'wohnung', 'wohnen', 'accommodation', 'housing'));
        $wants_language = $this->is_language_level_reply($question) || $this->text_matches_patterns($question, array('sprachniveau', 'deutschkenntnisse', 'sprachkurs', 'language level'));
        $wants_start_dates = $this->is_start_date_request($question);
        $wants_flights = $this->text_matches_patterns($question, array('flight', 'flights', 'flug', 'fluege'), false);
        $wants_relocation = $this->text_matches_patterns($question, array('relocation', 'move to germany', 'moving to germany', 'assist', 'support'), false);

        if ('English' === $reply_language) {
            $display_sources = ! empty($display_sources) ? $display_sources : $this->prepare_sources($raw_sources);
            $display_sources = array_values(
                array_filter($display_sources, function ($source) {
                    return ! empty($source['url']);
                })
            );

            if ($wants_flights) {
                $answer = 'I do not have reliable information that flights are covered or organised. For international applications and the next steps, please use AschkeCare-Connect';
                if (! empty($display_sources[0]['url'])) {
                    $answer .= ': ' . $display_sources[0]['url'];
                } else {
                    $answer .= '.';
                }

                return $answer;
            }

            if ($wants_accommodation) {
                if (! empty($display_sources[0]['url'])) {
                    return 'For accommodation, the international information mentions the Azubi-Haus with single rooms, shared kitchen and Wi-Fi. You can find the matching information here: ' . $display_sources[0]['url'];
                }

                return 'For accommodation, the international information mentions the Azubi-Haus with single rooms, shared kitchen and Wi-Fi.';
            }

            if ($wants_language) {
                if (! empty($display_sources[0]['url'])) {
                    return 'For language requirements and support, please check the international information here: ' . $display_sources[0]['url'];
                }

                return 'For language requirements and support, please use the international information page on our website.';
            }

            if ($wants_relocation || $this->text_matches_patterns($question, array('south africa', 'kenya', 'africa', 'from abroad', 'abroad'))) {
                if (! empty($display_sources[0]['url'])) {
                    return 'For applicants from abroad, AschkeCare-Connect is the right starting point. It gives information about international applications and support with the process: ' . $display_sources[0]['url'];
                }

                return 'For applicants from abroad, AschkeCare-Connect is the right starting point for international applications and support with the process.';
            }

            if (! empty($display_sources[0]['url'])) {
                return 'For international applications and support, the matching information is here: ' . $display_sources[0]['url'];
            }

            return 'For international applications and support, please use the international information page on our website.';
        }

        if (($wants_accommodation || $wants_language || $wants_start_dates) && is_array($faq_source)) {
            $faq_answer = trim((string) ($faq_source['content'] ?? ''));
            if ('' !== $faq_answer) {
                return $faq_answer;
            }
        }

        $display_sources = ! empty($display_sources) ? $display_sources : $this->prepare_sources($raw_sources);
        $display_sources = array_values(
            array_filter($display_sources, function ($source) {
                return ! empty($source['url']);
            })
        );

        if ($this->text_matches_patterns($question, array('bewerbung', 'bewerben', 'admission', 'apply', 'ausbildung', 'nursing', 'pflege', 'au pair', 'aupair'))) {
            if (! empty($display_sources[0]['url'])) {
                return 'Wenn du dich aus dem Ausland oder mit Au-pair-Hintergrund fuer eine Pflegeausbildung interessierst, findest du hier den passenden Einstieg: ' . $display_sources[0]['url'];
            }

            return 'Wenn du dich aus dem Ausland oder mit Au-pair-Hintergrund fuer eine Pflegeausbildung interessierst, nutze bitte die passende Informationsseite auf unserer Website.';
        }

        if (is_array($faq_source)) {
            $faq_answer = trim((string) ($faq_source['content'] ?? ''));
            if ('' !== $faq_answer) {
                return $faq_answer;
            }
        }

        if (! empty($display_sources[0]['url'])) {
            return 'Die passenden Informationen fuer internationale Bewerbungen und Begleitung findest du hier: ' . $display_sources[0]['url'];
        }

        return 'Die passenden Informationen fuer internationale Bewerbungen und Begleitung findest du auf unserer Website.';
    }

    private function pick_international_sources(array $sources, $query)
    {
        $international_sources = array_values(
            array_filter($sources, function ($source) {
                return $this->is_international_related_source($source);
            })
        );

        if (empty($international_sources)) {
            return array();
        }

        $focused_sources = $this->filter_sources_by_focus_tokens($international_sources, $query);
        if (! empty($focused_sources)) {
            $international_sources = $focused_sources;
        }

        usort($international_sources, function ($left, $right) use ($query) {
            $left_score = $this->score_international_source_for_query($left, $query);
            $right_score = $this->score_international_source_for_query($right, $query);

            if ($left_score === $right_score) {
                return 0;
            }

            return ($left_score > $right_score) ? -1 : 1;
        });

        return array_slice($international_sources, 0, 5);
    }

    private function is_international_related_source($source)
    {
        return $this->source_contains_patterns(
            $source,
            array(
                'care connect',
                'aschkecare connect',
                'internationale pflegekraefte',
                'internationale bewerbung',
                'bewerbung aus dem ausland',
                'azubi haus',
                'unterkunft',
                'wohnung',
                'housing',
                'accommodation',
                'a2',
                'b1',
                'b2',
                'c1',
                'deutschkenntnisse',
                'sprachkurs',
                'visum',
                'einreise',
                'anerkennung',
            )
        );
    }

    private function score_international_source_for_query(array $source, $query)
    {
        $haystack = implode(
            "\n",
            array(
                (string) ($source['title'] ?? ''),
                (string) ($source['url'] ?? ''),
                (string) ($source['content'] ?? ''),
            )
        );
        $score = $this->knowledge_base->phrase_score($query, $haystack, 2.3)
            + ($this->knowledge_base->keyword_score($query, $haystack) * 1.9)
            + ((float) ($source['score'] ?? 0) * 0.25);

        if ($this->text_matches_patterns($query, array('unterkunft', 'wohnung', 'wohnen', 'accommodation', 'housing')) && $this->source_contains_patterns($source, array('unterkunft', 'wohnung', 'azubi haus', 'housing', 'accommodation'))) {
            $score += 2.2;
        }

        if (($this->is_language_level_reply($query) || $this->text_matches_patterns($query, array('sprachkurs', 'sprachniveau', 'deutschkenntnisse'))) && $this->source_contains_patterns($source, array('a2', 'b1 b2', 'sprachkurs', 'internationale pflegekraefte'))) {
            $score += 2.0;
        }

        if ($this->is_start_date_request($query) && $this->source_contains_patterns($source, array('pflegefachkraft', 'pflegefachassistenz', 'care connect'))) {
            $score += 1.8;
        }

        return $score;
    }

    private function pick_international_faq_source($question, array $sources)
    {
        $faq_sources = array_values(
            array_filter($sources, function ($source) {
                return is_array($source) && (($source['type'] ?? '') === 'faq') && $this->is_international_related_source($source);
            })
        );

        if (empty($faq_sources)) {
            return null;
        }

        usort($faq_sources, function ($left, $right) use ($question) {
            $left_score = $this->score_international_source_for_query($left, $question);
            $right_score = $this->score_international_source_for_query($right, $question);

            if ($left_score === $right_score) {
                return 0;
            }

            return ($left_score > $right_score) ? -1 : 1;
        });

        return $faq_sources[0];
    }

    private function is_international_candidate_request($text)
    {
        $normalized = $this->knowledge_base->normalize_for_match((string) $text);
        if ('' === $normalized) {
            return false;
        }

        if ($this->question_explicitly_mentions_foreign_context($normalized)) {
            return true;
        }

        if ($this->text_matches_patterns($normalized, array('au pair', 'aupair', 'foreign', 'from abroad', 'abroad', 'visa', 'visum', 'anerkennung', 'care connect'))) {
            return true;
        }

        if ($this->question_may_need_international_support($normalized)) {
            return true;
        }

        return false;
    }

    private function question_may_need_international_support($text)
    {
        $normalized = $this->knowledge_base->normalize_for_match((string) $text);
        if ('' === $normalized) {
            return false;
        }

        if ($this->is_language_level_reply($normalized)) {
            return true;
        }

        return $this->text_matches_patterns(
            $normalized,
            array(
                'au pair',
                'aupair',
                'unterkunft',
                'wohnung',
                'wohnen',
                'accommodation',
                'housing',
                'sprachniveau',
                'deutschkenntnisse',
                'sprachkurs',
                'language level',
                'south africa',
                'kenya',
                'africa',
                'relocation',
                'move to germany',
                'moving to germany',
                'flight',
                'flights',
                'visum',
                'visa',
            )
        );
    }

    private function is_candidate_profile_reply($text)
    {
        $normalized = $this->knowledge_base->normalize_for_match((string) $text);
        if ('' === $normalized) {
            return false;
        }

        if ($this->is_language_level_reply($normalized)) {
            return true;
        }

        return $this->text_matches_patterns(
            $normalized,
            array(
                'au pair',
                'aupair',
                'ich bin au pair',
                'ich bin aupair',
                'i am an au pair',
                'im an au pair',
                'ich komme aus dem ausland',
                'aus dem ausland',
                'international',
            )
        );
    }

    private function is_language_level_reply($text)
    {
        $normalized = $this->knowledge_base->normalize_for_match((string) $text);
        if ('' === $normalized) {
            return false;
        }

        return preg_match('/\b(a1|a2|b1|b2|c1|c2)\b/u', $normalized) === 1;
    }

    private function source_matches_focus_tokens($source, array $tokens)
    {
        $haystack = $this->knowledge_base->normalize_for_match(
            implode(
                "\n",
                array(
                    (string) ($source['title'] ?? ''),
                    (string) ($source['url'] ?? ''),
                    (string) ($source['content'] ?? ''),
                )
            )
        );
        $compact_haystack = str_replace(' ', '', $haystack);

        foreach ($tokens as $token) {
            if (mb_strlen($token) < 4) {
                continue;
            }

            $normalized_token = $this->knowledge_base->normalize_for_match($token);
            $compact_token = str_replace(' ', '', $normalized_token);

            if (
                false !== mb_strpos($haystack, $normalized_token) ||
                ('' !== $compact_token && false !== mb_strpos($compact_haystack, $compact_token))
            ) {
                return true;
            }
        }

        return false;
    }

    private function should_require_focus_match($query)
    {
        $focus_tokens = $this->extract_focus_tokens($query);
        if (empty($focus_tokens)) {
            return false;
        }

        if (
            $this->is_event_request($query) ||
            $this->is_training_or_application_request($query) ||
            $this->is_job_or_career_request($query) ||
            $this->is_international_candidate_request($query) ||
            $this->is_location_or_school_request($query) ||
            $this->is_navigation_request($query, array()) ||
            $this->is_operational_student_request($query)
        ) {
            return false;
        }

        foreach ($focus_tokens as $token) {
            if (mb_strlen((string) $token) >= 6) {
                return true;
            }
        }

        return false;
    }

    private function is_foreign_specific_source($source)
    {
        $haystack = mb_strtolower(
            implode(
                "\n",
                array(
                    (string) ($source['title'] ?? ''),
                    (string) ($source['url'] ?? ''),
                    (string) ($source['content'] ?? ''),
                )
            )
        );

        $patterns = array(
            'care connect',
            'care-connect',
            'aschkecare',
            'international',
            'ausland',
            'anerkennung',
            'kenntnispr',
            'aufenthaltstitel',
            'visum',
            'rekrutierung internationaler',
        );

        foreach ($patterns as $pattern) {
            if (false !== mb_strpos($haystack, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function is_off_topic_source_for_query($source, $query)
    {
        $query = $this->knowledge_base->normalize_for_match((string) $query);

        if (! $this->is_privacy_query($query) && $this->is_privacy_specific_source($source)) {
            return true;
        }

        if (! $this->is_application_query($query) && ! $this->is_job_or_career_request($query) && $this->is_application_specific_source($source)) {
            return true;
        }

        if (! $this->is_event_request($query) && $this->is_event_related_source($source)) {
            return true;
        }

        if (! $this->is_job_or_career_request($query) && $this->is_job_related_source($source)) {
            return true;
        }

        if (! $this->is_international_candidate_request($query) && ! $this->question_may_need_international_support($query) && $this->is_international_related_source($source)) {
            return true;
        }

        return false;
    }

    private function is_privacy_query($query)
    {
        $patterns = array(
            'datenschutz',
            'dsgvo',
            'gdpr',
            'loeschung',
            'löschung',
            'meine daten',
            'auskunft',
            'privacy',
        );

        foreach ($patterns as $pattern) {
            if (false !== mb_strpos($query, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function is_application_query($query)
    {
        $patterns = array(
            'bewerbung',
            'bewerben',
            'lebenslauf',
            'unterlagen',
            'anschreiben',
            'cv',
            'application',
            'apply',
            'documents',
            'submit',
            'bewerbungsformular',
        );

        foreach ($patterns as $pattern) {
            if (false !== mb_strpos($query, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function is_privacy_specific_source($source)
    {
        return $this->source_contains_patterns(
            $source,
            array('datenschutz', 'dsgvo', 'gdpr', 'privacy', 'personenbezogen', 'betroffenenrechte')
        );
    }

    private function is_application_specific_source($source)
    {
        return $this->source_contains_patterns(
            $source,
            array('bewerbung', 'bewerben', 'lebenslauf', 'unterlagen', 'care connect', 'rekrutierung', 'vorauswahl')
        );
    }

    private function source_contains_patterns($source, array $patterns)
    {
        $haystack = $this->knowledge_base->normalize_for_match(
            implode(
                "\n",
                array(
                    (string) ($source['title'] ?? ''),
                    (string) ($source['url'] ?? ''),
                    (string) ($source['content'] ?? ''),
                )
            )
        );

        foreach ($patterns as $pattern) {
            $pattern = $this->knowledge_base->normalize_for_match($pattern);
            if ('' === $pattern) {
                continue;
            }

            if (false !== mb_strpos($haystack, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function text_matches_patterns($text, array $patterns, $allow_fuzzy = true)
    {
        $normalized = $this->knowledge_base->normalize_for_match((string) $text);
        if ('' === $normalized) {
            return false;
        }

        $compact_text = str_replace(' ', '', $normalized);
        $tokens = $this->knowledge_base->tokenize($normalized);

        foreach ($patterns as $pattern) {
            $pattern = $this->knowledge_base->normalize_for_match($pattern);
            if ('' === $pattern) {
                continue;
            }

            if (false !== mb_strpos($normalized, $pattern)) {
                return true;
            }

            $compact_pattern = str_replace(' ', '', $pattern);
            if (mb_strlen($compact_pattern) >= 4 && false !== mb_strpos($compact_text, $compact_pattern)) {
                return true;
            }

            if (! $allow_fuzzy) {
                continue;
            }

            if ($this->tokens_match_pattern($tokens, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function tokens_match_pattern(array $tokens, $pattern)
    {
        $pattern_tokens = $this->knowledge_base->tokenize($pattern);
        if (empty($tokens) || empty($pattern_tokens)) {
            return false;
        }

        $significant_pattern_tokens = array_values(
            array_filter($pattern_tokens, function ($token) {
                return mb_strlen((string) $token) >= 4;
            })
        );

        if (empty($significant_pattern_tokens)) {
            return false;
        }

        foreach ($significant_pattern_tokens as $pattern_token) {
            $matched = false;
            foreach ($tokens as $token) {
                if ($this->token_approximately_matches($token, $pattern_token)) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    private function token_approximately_matches($left, $right)
    {
        $left = $this->knowledge_base->normalize_for_match((string) $left);
        $right = $this->knowledge_base->normalize_for_match((string) $right);

        if ('' === $left || '' === $right) {
            return false;
        }

        if ($left === $right) {
            return true;
        }

        $left_length = mb_strlen($left);
        $right_length = mb_strlen($right);
        $shorter_length = min($left_length, $right_length);
        $longer_length = max($left_length, $right_length);

        if ($shorter_length >= 5) {
            $shorter = $left_length <= $right_length ? $left : $right;
            $longer = $left_length <= $right_length ? $right : $left;
            if (0 === mb_strpos($longer, $shorter) && ($shorter_length / max(1, $longer_length)) >= 0.6) {
                return true;
            }
        }

        if (! function_exists('levenshtein') || $longer_length < 5 || abs($left_length - $right_length) > 2) {
            return false;
        }

        $max_distance = $longer_length >= 9 ? 2 : 1;

        return levenshtein($left, $right) <= $max_distance;
    }

    private function append_relevant_links_to_answer($text, array $sources)
    {
        $text = trim((string) $text);
        $link_sources = $this->filter_sources_by_type($sources, array('page', 'contact', 'privacy'));
        $link_sources = array_values(
            array_filter($link_sources, function ($source) {
                return ! empty($source['url']);
            })
        );

        if (empty($link_sources) || $this->answer_already_contains_source_url($text, $link_sources)) {
            return $text;
        }

        $link_sources = array_slice($link_sources, 0, 2);

        if (1 === count($link_sources)) {
            return $text . "\n\n" . $link_sources[0]['url'];
        }

        return $text . "\n\n" .
            $link_sources[0]['url'] . "\n" .
            $link_sources[1]['url'];
    }

    private function answer_already_contains_source_url($text, array $sources)
    {
        $text = html_entity_decode((string) $text, ENT_QUOTES);

        foreach ($sources as $source) {
            $url = trim((string) ($source['url'] ?? ''));
            if ('' === $url) {
                continue;
            }

            if (false !== strpos($text, $url)) {
                return true;
            }

            $normalized_url = $this->normalize_allowed_url($url);
            if ('' !== $normalized_url && false !== strpos($text, $normalized_url)) {
                return true;
            }
        }

        return false;
    }

    private function get_direct_faq_answer($question, array $sources, array $confidence, $force_operational_faq = false)
    {
        $top_source = $this->pick_direct_faq_source($question, $sources, $force_operational_faq);
        if (! is_array($top_source) || ($top_source['type'] ?? '') !== 'faq') {
            return null;
        }

        $answer = trim((string) ($top_source['content'] ?? ''));
        if ('' === $answer) {
            return null;
        }

        $best_score = (float) ($confidence['best_score'] ?? 0);
        $gap = (float) ($confidence['gap'] ?? 0);
        $debug = is_array($top_source['debug'] ?? null) ? $top_source['debug'] : array();
        $title_signal = (float) ($debug['keyword_title'] ?? 0) + (float) ($debug['phrase_title'] ?? 0) + (float) ($debug['title_overlap'] ?? 0);
        $body_signal = (float) ($debug['keyword_body'] ?? 0) + (float) ($debug['phrase_body'] ?? 0);
        $is_link_or_course_intent = $this->is_training_or_application_request($question) || $this->is_navigation_request($question, array());
        $question_signal = $this->knowledge_base->phrase_score($question, $top_source['title'] ?? '', 2.0)
            + $this->knowledge_base->keyword_score($question, ($top_source['title'] ?? '') . ' ' . ($top_source['content'] ?? '')) * 1.6;

        if (
            $force_operational_faq &&
            (
                $best_score >= 2.0 ||
                $question_signal >= 1.6 ||
                ($title_signal >= 1.2 && $body_signal >= 0.6)
            )
        ) {
            return $answer;
        }

        if (
            ! $is_link_or_course_intent &&
            $title_signal >= 1.6 &&
            (
                $best_score >= 3.8 ||
                ($best_score >= 2.6 && $gap >= 0.45) ||
                $title_signal >= 2.6 ||
                ($title_signal >= 1.9 && $body_signal >= 0.8)
            )
        ) {
            return $answer;
        }

        return null;
    }

    private function pick_direct_faq_source($question, array $sources, $force_operational_faq = false)
    {
        $top_source = $sources[0] ?? null;
        if (! $force_operational_faq) {
            return $top_source;
        }

        $faq_sources = array_values(
            array_filter($sources, function ($source) {
                return is_array($source) && (($source['type'] ?? '') === 'faq');
            })
        );

        if (empty($faq_sources)) {
            return $top_source;
        }

        $operational_focus_tokens = $this->get_operational_focus_tokens($question);
        if (! empty($operational_focus_tokens)) {
            $focused_faq_sources = array_values(
                array_filter($faq_sources, function ($source) use ($operational_focus_tokens) {
                    return $this->source_contains_patterns($source, $operational_focus_tokens);
                })
            );

            if (empty($focused_faq_sources)) {
                return null;
            }

            $faq_sources = $focused_faq_sources;
        }

        usort($faq_sources, function ($left, $right) use ($question) {
            return $this->compare_faq_sources_for_question($left, $right, $question);
        });

        return $faq_sources[0];
    }

    private function compare_faq_sources_for_question(array $left, array $right, $question)
    {
        $left_score = $this->score_faq_source_for_question($left, $question);
        $right_score = $this->score_faq_source_for_question($right, $question);

        if ($left_score === $right_score) {
            return 0;
        }

        return ($left_score > $right_score) ? -1 : 1;
    }

    private function score_faq_source_for_question(array $source, $question)
    {
        $title = (string) ($source['title'] ?? '');
        $content = (string) ($source['content'] ?? '');
        $debug = is_array($source['debug'] ?? null) ? $source['debug'] : array();

        return $this->knowledge_base->phrase_score($question, $title, 2.0)
            + ($this->knowledge_base->keyword_score($question, $title . ' ' . $content) * 1.8)
            + (float) ($debug['keyword_title'] ?? 0)
            + (float) ($debug['phrase_title'] ?? 0)
            + (float) ($debug['title_overlap'] ?? 0)
            + ((float) ($source['score'] ?? 0) * 0.25);
    }

    private function get_operational_focus_tokens($text)
    {
        $text = $this->knowledge_base->normalize_for_match((string) $text);
        $tokens = array();
        $has_sick_intent = false;
        $has_lateness_intent = false;

        if (preg_match('/krank melden|krankmeldung|krankmelden|ich bin krank|fehle heute|abmelden|entschuldig/u', $text)) {
            $has_sick_intent = true;
            $tokens = array_merge($tokens, array('krank', 'krankmeldung', 'krank melden', 'krankmelden', 'abmelden', 'fehlen', 'entschuldigung'));
        }

        if (preg_match('/zu spaet|zu spat|verspaet|verspat|komme spaeter|komme spater/u', $text)) {
            $has_lateness_intent = true;
            $tokens = array_merge($tokens, array('spaet', 'zu spaet', 'verspaet', 'verspaetung', 'verspaetung melden'));
        }

        if (! $has_sick_intent && ! $has_lateness_intent && false !== mb_strpos($text, 'unterricht')) {
            $tokens[] = 'unterricht';
        }

        if (! $has_sick_intent && ! $has_lateness_intent && preg_match('/praxisstelle|praxis|praktikum/u', $text)) {
            $tokens = array_merge($tokens, array('praxisstelle', 'praxis', 'praktikum'));
        }

        return array_values(array_unique($tokens));
    }

    private function build_navigation_answer(array $sources, $reply_language = 'Deutsch')
    {
        $sources = array_values(
            array_filter($sources, function ($source) {
                return ! empty($source['url']);
            })
        );

        if (empty($sources)) {
            return $this->phrase($reply_language, 'Ich kann dich nicht direkt weiterleiten. Am besten nutze die passende Seite oder das Kontaktformular auf unserer Website.', 'I cannot redirect you directly. Please use the matching page or the contact form on our website.');
        }

        if (1 === count($sources)) {
            return $this->phrase($reply_language, 'Am passendsten ist diese Seite: ', 'The best matching page is: ') . $sources[0]['url'];
        }

        return $this->phrase($reply_language, 'Am passendsten sind diese Seiten: ', 'The best matching pages are: ') . $sources[0]['url'] . $this->phrase($reply_language, ' oder ', ' or ') . $sources[1]['url'];
    }

    private function build_operational_guidance_answer($question, array $sources, $reply_language = 'Deutsch')
    {
        $normalized_question = $this->knowledge_base->normalize_for_match((string) $question);

        if ('English' === $reply_language) {
            if ($this->is_clear_apprentio_access_problem($normalized_question) || (preg_match('/traeger|trager/u', $normalized_question) && preg_match('/zugang|access|login|link|reinkommt|nicht rein/u', $normalized_question))) {
                $answer = 'For APPRENTIO access or a missing access link, please contact the school or the responsible contact person directly.';
            } elseif (preg_match('/sick|ill|absent|krank melden|krankmeldung|krankmelden|ich bin krank|fehle heute|abmelden|entschuldig/u', $normalized_question)) {
                $answer = 'If you need to report sickness or absence, please contact the school or the responsible contact person directly.';
            } elseif (preg_match('/late|delay|zu spaet|zu spat|verspaet|verspat|komme spaeter|komme spater/u', $normalized_question)) {
                $answer = 'If you need to report a delay, please contact the school or the responsible contact person directly.';
            } else {
                $answer = 'Please contact the school or the responsible contact person directly for this organisational matter.';
            }

            if (! empty($sources[0]['url'])) {
                $answer .= ' The best page is: ' . $sources[0]['url'];
            }

            return $answer;
        }

        if ($this->is_clear_apprentio_access_problem($normalized_question) || (preg_match('/traeger|trager/u', $normalized_question) && preg_match('/zugang|access|login|link|reinkommt|nicht rein/u', $normalized_question))) {
            $answer = 'Wenn der Traeger keinen APPRENTIO-Zugang oder keinen Zugangslink bekommen hat, nimm bitte direkt Kontakt mit der Schule oder dem zustaendigen Ansprechpartner auf.';
        } elseif (preg_match('/krank melden|krankmeldung|krankmelden|ich bin krank|fehle heute|abmelden|entschuldig/u', $normalized_question)) {
            $answer = 'Wenn du dich krankmelden moechtest, nimm bitte direkt Kontakt mit der Schule oder dem zustaendigen Ansprechpartner auf.';
        } elseif (preg_match('/zu spaet|zu spat|verspaet|verspat|komme spaeter|komme spater/u', $normalized_question)) {
            $answer = 'Wenn du wegen einer Verspaetung Bescheid geben moechtest, nimm bitte direkt Kontakt mit der Schule oder dem zustaendigen Ansprechpartner auf.';
        } elseif (preg_match('/praxisstelle/u', $normalized_question)) {
            $answer = 'Bei Fragen zur Praxisstelle nimm bitte direkt Kontakt mit der Schule oder dem zustaendigen Ansprechpartner auf.';
        } else {
            $answer = 'Bitte nimm dafuer direkt Kontakt mit der Schule oder dem zustaendigen Ansprechpartner auf.';
        }

        if (! empty($sources[0]['url'])) {
            $answer .= ' Am passendsten ist diese Seite: ' . $sources[0]['url'];
        }

        return $answer;
    }

    private function build_retrieval_query($question, array $history)
    {
        if (! $this->should_use_history_context($question, $history)) {
            return $question;
        }

        $recent_user_messages = array();

        foreach (array_reverse($history) as $item) {
            if (($item['role'] ?? '') !== 'user') {
                continue;
            }

            $content = $this->knowledge_base->normalize_content($item['content'] ?? '');
            if ('' === $content) {
                continue;
            }

            $recent_user_messages[] = $content;
            if (count($recent_user_messages) >= 2) {
                break;
            }
        }

        $recent_user_messages = array_reverse($recent_user_messages);

        if (empty($recent_user_messages)) {
            return $question;
        }

        return implode("\n", $recent_user_messages) . "\n" . $question;
    }

    private function augment_query_with_aliases($text)
    {
        $text = trim((string) $text);
        if ('' === $text) {
            return '';
        }

        $normalized = $this->knowledge_base->normalize_for_match($text);
        $aliases = array();

        if (preg_match('/bewerb|apply|application/u', $normalized)) {
            $aliases = array_merge($aliases, array('bewerbung', 'bewerben', 'anmeldung', 'unterlagen', 'bewerbungsformular'));
        }

        if (preg_match('/ausbild|training|nursing|nurse assistant/u', $normalized) || preg_match('/pflegefach/u', $normalized)) {
            $aliases = array_merge($aliases, array('ausbildung', 'pflegeausbildung', 'kursangebote pflegeschule'));
        }

        if (preg_match('/pflegefachkraft|pflegefachkraefte/u', $normalized)) {
            $aliases = array_merge($aliases, array('pflegefachmann', 'pflegefachfrau', 'pfk', '3-jaehrige ausbildung', 'generalistische pflegeausbildung'));
        }

        if (preg_match('/\bpfa\b|pflegefachassisten|pflegeassisten/u', $normalized)) {
            $aliases = array_merge($aliases, array('pflegefachassistenz', '1-jaehrige ausbildung', 'pflegeassistenz', 'pflegehelferin'));
        }

        if (preg_match('/betreuungskraft|alltagsbegleiter|43b|43 b/u', $normalized)) {
            $aliases = array_merge($aliases, array('betreuungskraft', 'alltagsbegleiter', 'paragraph 43b', 'qualifizierung betreuungskraft'));
        }

        if (preg_match('/weiterbild|fortbild|qualifiz/u', $normalized)) {
            $aliases = array_merge($aliases, array('weiterbildung', 'fortbildung', 'kursangebote bildung karriere'));
        }

        if (preg_match('/voraussetz|was brauche|was braucht/u', $normalized)) {
            $aliases = array_merge($aliases, array('voraussetzungen', 'zugangsvoraussetzungen', 'unterlagen'));
        }

        if (preg_match('/wie lange|dauer|dauert/u', $normalized)) {
            $aliases = array_merge($aliases, array('dauer', 'ausbildungsdauer'));
        }

        if (preg_match('/wo ist|standort|adresse|anschrift|schule|pflegeschule/u', $normalized)) {
            $aliases = array_merge($aliases, array('standort', 'adresse', 'pflegeschule hagen', 'alte schraubenfabrik'));
        }

        if ($this->is_event_request($normalized)) {
            $aliases = array_merge($aliases, array('veranstaltungen', 'unsere veranstaltungen'));
        }

        if ($this->query_mentions_bfe($normalized)) {
            $aliases = array_merge($aliases, array('berufsfelderkundung', 'berufsfelderkundungstag', 'bfe', 'unsere veranstaltungen'));
        }

        if ($this->is_international_candidate_request($normalized) || $this->question_may_need_international_support($normalized)) {
            $aliases = array_merge($aliases, array('care connect', 'aschkecare connect', 'internationale pflegekraefte'));
        }

        if ($this->text_matches_patterns($normalized, array('relocation', 'move to germany', 'moving to germany', 'south africa', 'kenya', 'africa', 'from abroad', 'flights'), false)) {
            $aliases = array_merge($aliases, array('care connect', 'aschkecare connect', 'internationale pflegekraefte', 'einreise', 'unterkunft'));
        }

        if ($this->text_matches_patterns($normalized, array('unterkunft', 'wohnung', 'wohnen', 'accommodation', 'housing'))) {
            $aliases = array_merge($aliases, array('unterkunft', 'wohnung', 'azubi haus', 'housing', 'accommodation'));
        }

        if ($this->is_language_level_reply($normalized)) {
            $aliases = array_merge($aliases, array('a2 niveau', 'sprachkurs b1 b2', 'internationale pflegekraefte'));
        }

        $has_job_role = $this->query_mentions_pedagogy_role($normalized) || $this->query_mentions_dozent_role($normalized);
        $has_job_search_intent = (bool) preg_match('/\b(suche|suchen)\b/u', $normalized);
        $has_strong_job_intent = (bool) preg_match('/stellenangebot|stellenangebote|offene stelle|offene stellen|\bjobs?\b|arbeiten bei|karriere bei|initiativbewerbung|bewerbung als|bewerben als|wir suchen|gesucht|vakanz/u', $normalized);

        if ($has_job_role) {
            $aliases = array_merge($aliases, array('pflegepaedagogin', 'pflegepaedagoge', 'lehrkraft pflegeberufe', 'hauptamtliche lehrkraft', 'dozent berufliche weiterbildung'));
        }

        if ($has_strong_job_intent || ($has_job_role && $has_job_search_intent)) {
            $aliases = array_merge($aliases, array('stellenangebote', 'arbeiten'));
        }

        $has_lateness_intent = (bool) preg_match('/zu spaet|zu spät|verspaet|verspät|komme spaeter|komme später/u', $normalized);
        $has_sick_intent = (bool) preg_match('/krank melden|krankmeldung|krankmelden|ich bin krank|fehle heute|abmelden|entschuldigen/u', $normalized);
        $has_practice_intent = (bool) preg_match('/praxisstelle|praxis|praktikum/u', $normalized);

        if ($has_lateness_intent) {
            $aliases = array_merge($aliases, array('verspaetung', 'verspaetung melden', 'zu spaet'));
        }

        if ($has_sick_intent) {
            $aliases = array_merge($aliases, array('krankmeldung', 'krankmelden', 'entschuldigung', 'fehlzeit'));
        }

        if (! $has_lateness_intent && ! $has_sick_intent && false !== mb_strpos($normalized, 'unterricht')) {
            $aliases[] = 'unterricht';
        }

        if (! $has_lateness_intent && ! $has_sick_intent && $has_practice_intent) {
            $aliases = array_merge($aliases, array('praxisstelle', 'praxis', 'praktikum'));
        }

        if (false !== mb_strpos($normalized, 'pflegefachassistent')) {
            $aliases = array_merge($aliases, array('pflegefachassistenz', '1 jaehrige pflegefachassistenz'));
        }

        if (false !== mb_strpos($normalized, 'pflegefachassistenz')) {
            $aliases = array_merge($aliases, array('1 jaehrige pflegefachassistenz'));
        }

        if (false !== mb_strpos($normalized, 'pflegefachmann') || false !== mb_strpos($normalized, 'pflegefachfrau') || false !== mb_strpos($normalized, 'pflegefachkraft') || preg_match('/\bpfk\b/u', $normalized)) {
            $aliases = array_merge($aliases, array('pflegefachmann', 'pflegefachfrau', 'pflegefachkraft', '3 jaehrige pflegefachkraft', 'generalistische pflegeausbildung', 'pfk ausbildung'));
        }

        if (preg_match('/praxisanleit/u', $normalized)) {
            $aliases = array_merge($aliases, array('praxisanleiter', 'praxisanleitung', 'weiterbildung praxisanleitung'));
        }

        if (preg_match('/intensiv|intensivpflege|icu/u', $normalized)) {
            $aliases = array_merge($aliases, array('intensivpflege', 'fachweiterbildung intensiv', 'intensivstation'));
        }

        if (preg_match('/wundmanage|wundversorg|wound/u', $normalized)) {
            $aliases = array_merge($aliases, array('wundmanagement', 'wundversorgung'));
        }

        if (preg_match('/palliativ|hospiz|sterbebegleitung/u', $normalized)) {
            $aliases = array_merge($aliases, array('palliative care', 'palliativpflege', 'hospizarbeit'));
        }

        if (preg_match('/probezeit/u', $normalized)) {
            $aliases = array_merge($aliases, array('probezeit ausbildung', 'probezeit pflegefachkraft', 'probezeit pflegefachassistenz'));
        }

        if (preg_match('/foerd|bafog|finanzier|kostenlos|kostenfrei|kostenuebernahm|gefoerd/u', $normalized)) {
            $aliases = array_merge($aliases, array('foerderung', 'bafog', 'finanzierung ausbildung', 'kostenfreie ausbildung'));
        }

        if (preg_match('/schulabschluss|schulabschlus|hauptschule|realschule|abitur|voraussetz/u', $normalized)) {
            $aliases = array_merge($aliases, array('voraussetzungen', 'schulabschluss', 'zugangsvoraussetzungen', 'was brauche ich'));
        }

        if (false !== mb_strpos($normalized, 'palliativecare')) {
            $aliases[] = 'palliative care';
        }

        $aliases = array_values(array_unique(array_filter($aliases)));

        if (empty($aliases)) {
            return $text;
        }

        return $text . "\n" . implode("\n", $aliases);
    }

    private function should_use_history_context($question, array $history)
    {
        $normalized = $this->knowledge_base->normalize_for_match((string) $question);
        if ('' === $normalized) {
            return false;
        }

        $is_compact_followup = $this->is_short_topic_followup($question) || $this->is_short_focus_query($question);

        if ($this->is_affirmative_reply($normalized)) {
            return $this->assistant_requested_contextual_followup($history) ||
                $this->assistant_requested_training_focus($history) ||
                $this->assistant_requested_link_confirmation($history) ||
                $this->assistant_requested_origin_clarification($history) ||
                $this->assistant_requested_visit_context($history);
        }

        if ($this->is_language_switch_request($normalized)) {
            return false;
        }

        if ($is_compact_followup && $this->is_candidate_profile_reply($normalized)) {
            return $this->assistant_requested_origin_clarification($history) ||
                $this->assistant_requested_contextual_followup($history) ||
                $this->question_explicitly_mentions_foreign_context($normalized);
        }

        $followup_patterns = array(
            '/\b(dafuer|dafur|dazu|damit|davon|dahin|dort)\b/u',
            '/^(und|oder|auch|noch)\b/u',
            '/^(welche werden angeboten|was wird angeboten|welche gibt es|was gibt es)\b/u',
            '/^(wie komme ich dahin|wo finde ich die|wo finde ich dazu|und die|und das)\b/u',
            '/\b(nicht meine frage|war nicht meine frage|falsch verstanden|nicht danach gefragt|meine frage war|ich meinte|ich meine)\b/u',
            '/\b(not my question|misunderstood|you misunderstood|i meant)\b/u',
        );

        foreach ($followup_patterns as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        if ($is_compact_followup && $this->question_has_standalone_topic_signal($normalized)) {
            return false;
        }

        if (
            ($is_compact_followup && $this->assistant_requested_contextual_followup($history)) ||
            ($is_compact_followup && $this->assistant_requested_training_focus($history)) ||
            ($is_compact_followup && $this->assistant_requested_link_confirmation($history)) ||
            ($is_compact_followup && $this->assistant_requested_origin_clarification($history)) ||
            ($is_compact_followup && $this->assistant_requested_visit_context($history))
        ) {
            return true;
        }

        return false;
    }

    private function question_has_standalone_topic_signal($question)
    {
        $normalized = $this->knowledge_base->normalize_for_match((string) $question);
        if ('' === $normalized || $this->is_candidate_profile_reply($normalized)) {
            return false;
        }

        $generic_tokens = array(
            'ja', 'nein', 'bitte', 'ok', 'okay', 'gern', 'gerne',
            'dafuer', 'dafur', 'dazu', 'damit', 'davon', 'dahin', 'dort',
            'und', 'oder', 'auch', 'noch', 'ich', 'bin', 'habe', 'haben',
            'sie', 'wir', 'mir', 'mich', 'mein', 'meine',
        );
        $tokens = array_values(
            array_filter($this->knowledge_base->tokenize($normalized), function ($token) use ($generic_tokens) {
                return mb_strlen((string) $token) >= 4 && ! in_array((string) $token, $generic_tokens, true);
            })
        );

        return count($tokens) >= 2 || (! empty($tokens[0]) && mb_strlen((string) $tokens[0]) >= 7);
    }

    private function sanitize_history(array $history)
    {
        $sanitized = array();
        foreach (array_slice($history, -6) as $item) {
            $role = ($item['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = $this->knowledge_base->redact_personal_data(
                $this->knowledge_base->normalize_content($item['content'] ?? '')
            );

            if ('' === $content) {
                continue;
            }

            $sanitized[] = array(
                'role' => $role,
                'content' => $content,
            );
        }

        return $sanitized;
    }

    private function is_personal_data_request($question, array $history)
    {
        $parts = array($question);
        $user_history_count = 0;

        foreach (array_reverse($history) as $item) {
            if (($item['role'] ?? '') !== 'user') {
                continue;
            }

            $content = trim((string) ($item['content'] ?? ''));
            if ('' === $content) {
                continue;
            }

            $parts[] = $content;
            $user_history_count++;

            if ($user_history_count >= 2) {
                break;
            }
        }

        $text = mb_strtolower(implode("\n", array_reverse($parts)));

        $patterns = array(
            'dsgvo',
            'gdpr',
            'datenschutz',
            'personenbezogen',
            'personenbezogene daten',
            'meine daten',
            'auskunft',
            'loeschung',
            'löschung',
            'berichtigen',
            'widerspruch',
            'widerruf',
            'datenkopie',
            'privacy',
            'personal data',
            'delete my data',
            'erase my data',
            'remove my data',
            'access my data',
        );

        foreach ($patterns as $pattern) {
            if (false !== mb_strpos($text, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function is_language_switch_request($question)
    {
        $normalized = $this->knowledge_base->normalize_for_match((string) $question);
        if ('' === $normalized) {
            return false;
        }

        $patterns = array(
            '/^(english|english please|english chat|speak english|switch to english|answer in english|reply in english|auf englisch|bitte englisch|englisch bitte)$/u',
            '/^(deutsch|deutsch bitte|bitte deutsch|auf deutsch|switch to german|answer in german|reply in german)$/u',
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }

    private function build_language_switch_answer($question)
    {
        $requested_language = $this->detect_requested_reply_language($question);

        if ('English' === $requested_language) {
            return 'Sure, I can answer in English. What would you like to know about our courses, applications, locations, or contact options?';
        }

        return 'Gern, ich antworte dir auf Deutsch. Wobei kann ich dir weiterhelfen?';
    }

    private function is_contact_form_required_request($question, array $history)
    {
        $text = mb_strtolower($this->build_detection_text($question, $history));
        if ($this->is_training_or_application_request($text) && $this->is_start_date_request($text)) {
            return false;
        }

        $patterns = array(
            'meeting',
            'appointment',
            'book',
            'booking',
            'buchen',
            'vereinbaren',
            'vereinbare',
            'reservieren',
            'rueckruf',
            'rückruf',
            'nachricht',
            'nachricht hinterlassen',
            'message for',
            'can you tell',
            'weiterleiten',
            'weiter geben',
            'weitergeben',
            'ausrichten',
            'kontakt aufnehmen fuer mich',
            'contact them for me',
            'leave a message',
            'verbinde mich',
            'verbinden sie mich',
            'stelle kontakt her',
            'melde mich an',
        );

        foreach ($patterns as $pattern) {
            if (false !== mb_strpos($text, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function is_navigation_request($question, array $history)
    {
        $text = mb_strtolower($this->build_detection_text($question, $history));
        $patterns = array(
            'leite mich',
            'weiterleiten',
            'weiterleite',
            'weiterleitung',
            'schick mir den link',
            'sende mir den link',
            'gib mir den link',
            'zeig mir den link',
            'wo finde ich',
            'welche seite',
            'zur seite',
            'seite weiter',
            'weiter zur seite',
            'zur passenden seite',
            'zum formular',
            'zur anmeldung',
            'zum kontaktformular',
            'link dazu',
            'passender link',
            'direct me',
            'send me the link',
            'show me the link',
            'which page',
        );

        foreach ($patterns as $pattern) {
            if (false !== mb_strpos($text, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function is_training_or_application_request($text)
    {
        $text = $this->knowledge_base->normalize_for_match((string) $text);
        if ($this->is_job_or_career_request($text) || $this->is_event_request($text)) {
            return false;
        }

        $patterns = array(
            'ausbildung',
            'ausbild',
            'weiterbildung',
            'weiterbild',
            'pflegefachmann',
            'pflegefachfrau',
            'pflegefachkraft',
            'pflegefachassistenz',
            'pflegefachassistent',
            'pflegefachassistentin',
            'pflegeassistent',
            'pflegeassistentin',
            'pflegeassistenz',
            'pflegehelfer',
            'pflegehelferin',
            'praxisanleiter',
            'praxisanleitung',
            'betreuungskraft',
            'alltagsbegleiter',
            'behandlungspflege',
            'lg1',
            'lg2',
            '53b',
            '43b',
            'palliative',
            'palliativ',
            'fortbildungsplan',
            'fortbildungsplaene',
            'fortbildungsplÃ¤ne',
            'bewerbung',
            'bewerben',
            'bewerbe',
            'bewerb',
            'application',
            'apply',
            'admission',
            'training',
            'nursing',
            'nurse',
            'anmeldung',
            'unterlagen',
            'documents',
            'voraussetzung',
            'voraussetzungen',
            'lebenslauf',
            'bildungsgutschein',
            'kurs',
            'seminar',
            'qualifizierung',
            'umschulung',
        );

        return $this->text_matches_patterns($text, $patterns);
    }

    private function is_general_application_request($text)
    {
        $text = $this->knowledge_base->normalize_for_match((string) $text);
        if ($this->is_job_or_career_request($text)) {
            return false;
        }

        $patterns = array(
            'wie bewerbe',
            'wie kann ich mich bewerben',
            'ich moechte mich bewerben',
            'ich möchte mich bewerben',
            'bewerbung',
            'bewerben',
            'bewerbe',
            'application',
            'apply',
            'admission',
            'bewerbungsformular',
            'lebenslauf',
            'unterlagen',
            'documents',
        );

        return $this->text_matches_patterns($text, $patterns);
    }

    private function is_event_request($text)
    {
        $normalized = $this->knowledge_base->normalize_for_match((string) $text);
        if ('' === $normalized) {
            return false;
        }

        if ($this->query_mentions_bfe($normalized)) {
            return true;
        }

        $patterns = array(
            'veranstaltung',
            'veranstaltungen',
            'event',
            'events',
            'ausbildungsmesse',
            'berufsbildungsmesse',
            'offener tag',
            'tag der pflege',
            'messe',
            'messen',
        );

        return $this->text_matches_patterns($normalized, $patterns, false);
    }

    private function is_job_or_career_request($text)
    {
        $text = $this->knowledge_base->normalize_for_match((string) $text);
        if ('' === $text) {
            return false;
        }

        $strong_job_patterns = array(
            'stellenangebot',
            'stellenangebote',
            'offene stelle',
            'offene stellen',
            'arbeiten bei',
            'karriere bei',
            'initiativbewerbung',
            'bewerbung als',
            'bewerben als',
            'wir suchen',
            'gesucht',
            'vakanz',
            'vakanzen',
        );
        $has_role_cue = $this->query_mentions_pedagogy_role($text) || $this->query_mentions_dozent_role($text);

        if ($this->text_matches_patterns($text, $strong_job_patterns)) {
            return true;
        }

        if (preg_match('/\bjobs?\b/u', $text)) {
            return true;
        }

        if ($has_role_cue && preg_match('/\b(suche|suchen|bewerb|stelle|stellen|job|karriere|arbeiten)\b/u', $text)) {
            return true;
        }

        if (
            $has_role_cue &&
            $this->is_short_focus_query($text) &&
            ! preg_match('/\b(ausbildung|weiterbildung|studium|kurs|qualifizierung)\b/u', $text)
        ) {
            return true;
        }

        return false;
    }

    private function is_operational_student_request($text)
    {
        $text = mb_strtolower((string) $text);
        $normalized = $this->knowledge_base->normalize_for_match((string) $text);

        if (preg_match('/praxisanleit|praxisanleiter|praxisanleitung/u', $normalized)) {
            return false;
        }

        // Informationsfragen sind keine operativen Anfragen — z.B. "Was macht man im Praktikum?"
        if (preg_match('/was (wurd|macht|passiert|erwartet|ist|gibt|beinhaltet|umfasst|lernt)|wie (ist|lauft|lauft|sieht|wird|sind)|was kann ich|welche (aufgaben|inhalte|tatigkeiten)/u', $normalized)) {
            return false;
        }

        $patterns = array(
            'apprentio',
            'zugangslink',
            'kein zugang',
            'nicht bei apprentio rein',
            'zu spaet',
            'zu spät',
            'verspaete',
            'verspäte',
            'verspaetung',
            'verspätung',
            'komme spaeter',
            'komme später',
            'komme zu spaet',
            'komme zu spät',
            'krank melden',
            'krankmelden',
            'krankmeldung',
            'ich bin krank',
            'fehle heute',
            'unterricht',
            'praxisstelle',
            'praxis',
            'fehlstunde',
            'abmelden',
            'entschuldigen',
            'verspaetung melden',
            'verspätung melden',
        );

        foreach ($patterns as $pattern) {
            if (false !== mb_strpos($text, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function is_location_or_school_request($text)
    {
        $text = mb_strtolower((string) $text);
        $patterns = array(
            'wo ist',
            'standort',
            'adresse',
            'anschrift',
            'schule',
            'pflegeschule',
            'gesundheitscampus',
            'hagen',
            'witten',
            'plessenstrasse',
            'plessenstraße',
        );

        foreach ($patterns as $pattern) {
            if (false !== mb_strpos($text, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function is_general_training_overview_request($text)
    {
        $text = mb_strtolower((string) $text);

        if (! $this->is_training_or_application_request($text)) {
            return false;
        }

        $patterns = array(
            'was gibt es',
            'welche gibt es',
            'was fuer welche',
            'was für welche',
            'welche weiterbildung',
            'weiterbildung machen',
            'interessiere mich fuer weiterbildung',
            'interessiere mich für weiterbildung',
        );

        foreach ($patterns as $pattern) {
            if (false !== mb_strpos($text, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function is_specific_training_topic_query($text)
    {
        $text = mb_strtolower((string) $text);
        if (! $this->is_training_or_application_request($text) || $this->is_general_training_overview_request($text)) {
            return false;
        }

        $generic_tokens = array(
            'pflege',
            'weiterbildung',
            'ausbildung',
            'bewerbung',
            'kurs',
            'seminar',
            'qualifizierung',
            'machen',
            'dauer',
            'wie',
            'lange',
            'angeboten',
            'anbieten',
        );

        foreach ($this->knowledge_base->tokenize($text) as $token) {
            if (mb_strlen($token) >= 5 && ! in_array($token, $generic_tokens, true)) {
                return true;
            }
        }

        return false;
    }

    private function is_short_topic_followup($text)
    {
        $text = trim((string) $text);
        $word_count = count(array_filter(preg_split('/\s+/u', $text)));

        return $text !== '' && $word_count <= 3 && mb_strlen($text) <= 35 && preg_match('/[[:alpha:]]/u', $text);
    }

    private function is_short_focus_query($text)
    {
        $text = trim((string) $text);
        $word_count = count(array_filter(preg_split('/\s+/u', $text)));

        if ($word_count > 5 || mb_strlen($text) > 60) {
            return false;
        }

        return preg_match('/[[:alpha:]]/u', $text) === 1;
    }

    private function is_affirmative_reply($question)
    {
        $question = trim(mb_strtolower((string) $question));
        $affirmatives = array(
            'ja',
            'ja bitte',
            'gern',
            'gerne',
            'okay',
            'ok',
            'bitte',
            'mach das',
            'zeigen',
            'schick',
        );

        return in_array($question, $affirmatives, true);
    }

    private function is_origin_clarification_answer($question)
    {
        $question = $this->knowledge_base->normalize_for_match((string) $question);
        $patterns = array(
            'deutschland',
            'aus deutschland',
            'in deutschland',
            'national',
            'ausland',
            'aus dem ausland',
            'international',
            'au pair',
            'aupair',
        );

        foreach ($patterns as $pattern) {
            if (false !== mb_strpos($question, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function response_contains_origin_loop($text)
    {
        $text = mb_strtolower(trim((string) $text));
        if ('' === $text) {
            return false;
        }

        $patterns = array(
            'aus deutschland oder aus dem ausland',
            'kommen sie aus deutschland oder aus dem ausland',
            'kommst du aus deutschland oder aus dem ausland',
            'national oder international',
            'moechten sie sich national oder international bewerben',
            'möchten sie sich national oder international bewerben',
        );

        foreach ($patterns as $pattern) {
            if (false !== mb_strpos($text, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function question_explicitly_mentions_foreign_context($text)
    {
        $text = $this->knowledge_base->normalize_for_match((string) $text);
        $patterns = array(
            'ausland',
            'aus dem ausland',
            'international',
            'anerkennung',
            'care connect',
            'visum',
            'visa',
            'au pair',
            'aupair',
        );

        foreach ($patterns as $pattern) {
            if (false !== mb_strpos($text, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function assistant_requested_link_confirmation(array $history)
    {
        $last_assistant_message = $this->get_last_assistant_message($history);
        if ('' === $last_assistant_message) {
            return false;
        }

        $patterns = array(
            'moechten sie den link',
            'möchten sie den link',
            'link jetzt',
            'direkten link',
            'soll ich ihnen den link',
            'soll ich dir den link',
            'link schicken',
            'link senden',
        );

        foreach ($patterns as $pattern) {
            if (false !== mb_strpos($last_assistant_message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function assistant_requested_origin_clarification(array $history)
    {
        $last_assistant_message = $this->get_last_assistant_message($history);
        if ('' === $last_assistant_message) {
            return false;
        }

        $patterns = array(
            'aus deutschland oder aus dem ausland',
            'kommen sie aus deutschland oder aus dem ausland',
            'kommst du aus deutschland oder aus dem ausland',
            'national oder international',
            'moechten sie sich national oder international bewerben',
            'möchten sie sich national oder international bewerben',
        );

        foreach ($patterns as $pattern) {
            if (false !== mb_strpos($last_assistant_message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function assistant_requested_training_focus(array $history)
    {
        $last_assistant_message = $this->get_last_assistant_message($history);
        if ('' === $last_assistant_message) {
            return false;
        }

        $patterns = array(
            'welche art weiterbildung',
            'welche dieser bereiche',
            'welche weiterbildung meinen sie',
            'welche weiterbildung meinst du',
            'wenn du eine richtung praezisierst',
            'wenn du eine richtung präzisierst',
            'genauen bereich',
            'genauen kursnamen',
            'genaue ausbildung oder weiterbildung',
        );

        foreach ($patterns as $pattern) {
            if (false !== mb_strpos($last_assistant_message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function assistant_requested_contextual_followup(array $history)
    {
        $last_assistant_message = $this->get_last_assistant_message($history);
        if ('' === $last_assistant_message) {
            return false;
        }

        $patterns = array(
            'soll ich ihnen kurz sagen',
            'soll ich dir kurz sagen',
            'soll ich ihnen kurz nennen',
            'soll ich dir kurz nennen',
            'moechten sie kurz wissen',
            'möchten sie kurz wissen',
            'moechtest du kurz wissen',
            'möchtest du kurz wissen',
            'moechten sie informationen zu',
            'möchten sie informationen zu',
            'moechtest du informationen zu',
            'möchtest du informationen zu',
            'voraussetzungen',
            'unterlagen',
            'anmeldung',
            'starttermine',
            'termine',
            'details einsehen',
        );

        foreach ($patterns as $pattern) {
            if (false !== mb_strpos($last_assistant_message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function assistant_requested_visit_context(array $history)
    {
        $last_assistant_message = $this->get_last_assistant_message($history);
        if ('' === $last_assistant_message) {
            return false;
        }

        $patterns = array(
            'zum unterricht',
            'zu einer praxisstelle',
            'zu einem beratungstermin',
            'ob sie zum unterricht',
            'ob du zum unterricht',
            'praxisstelle oder zu einem beratungstermin',
        );

        foreach ($patterns as $pattern) {
            if (false !== mb_strpos($last_assistant_message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function is_visit_context_answer($question)
    {
        $question = mb_strtolower(trim((string) $question));
        $patterns = array(
            'unterricht',
            'zum unterricht',
            'praxisstelle',
            'zur praxisstelle',
            'beratung',
            'beratungstermin',
            'zum beratungstermin',
        );

        foreach ($patterns as $pattern) {
            if (false !== mb_strpos($question, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function expand_affirmative_followup_question(array $history)
    {
        $last_assistant_message = $this->get_last_assistant_message($history);

        if (false !== mb_strpos($last_assistant_message, 'voraussetzung')) {
            return 'Welche Voraussetzungen sind fuer diese Ausbildung oder Weiterbildung erforderlich?';
        }

        if (false !== mb_strpos($last_assistant_message, 'unterlagen')) {
            return 'Welche Unterlagen werden fuer diese Ausbildung oder Weiterbildung benoetigt?';
        }

        if (false !== mb_strpos($last_assistant_message, 'anmeldung')) {
            return 'Wie laeuft die Anmeldung fuer diese Ausbildung oder Weiterbildung ab?';
        }

        if (false !== mb_strpos($last_assistant_message, 'starttermine') || false !== mb_strpos($last_assistant_message, 'termine')) {
            return 'Welche Starttermine gibt es fuer diese Ausbildung oder Weiterbildung?';
        }

        if (false !== mb_strpos($last_assistant_message, 'details')) {
            return 'Bitte nenne kurz die wichtigen Details zu dieser Ausbildung oder Weiterbildung.';
        }

        return 'Bitte nenne kurz die passenden Informationen zu dieser Ausbildung oder Weiterbildung.';
    }

    private function is_start_date_request($text)
    {
        $text = $this->knowledge_base->normalize_for_match((string) $text);
        if ($this->is_event_request($text)) {
            return false;
        }

        $patterns = array(
            'wann startet',
            'wann beginnt',
            'wann fangt',
            'wann faengt',
            'wann anfang',
            'anfangstermin',
            'starttermin',
            'starttermine',
            'beginn',
            'naechste kurs',
            'naechster kurs',
            'naechste ausbildung',
            'nächste ausbildung',
            'naechster ausbildungstermin',
            'ausbildungstermin',
            'kursstart',
            'startdatum',
            'start daten',
            'bis wann bewerben',
            'bis wann anmelden',
            'bewerbungsfrist',
            'anmeldefrist',
            'bewerbungsschluss',
            'anmeldeschluss',
            'application deadline',
            'deadline',
            'noch starten',
            'noch beginnen',
            'noch bewerben',
            'noch anmelden',
            'noch einsteigen',
            'jetzt noch',
            'noch zum',
            'zum 1.',
            'zum 01.',
            'zum oktober',
            'zum november',
            'zum januar',
            'zum februar',
            'zum maerz',
            'zum april',
            'zum september',
            'naechsten monat',
            'diesen monat',
            'im oktober',
            'im november',
            'im januar',
            'im februar',
            'im maerz',
            'im april',
            'im september',
        );

        foreach ($patterns as $pattern) {
            if (false !== mb_strpos($text, $pattern)) {
                return true;
            }
        }

        // Datum-Pattern wie "1.10.", "01.10", "1.10.2026"
        if (preg_match('/\b\d{1,2}\.\d{1,2}\.?\b/', $text)) {
            return true;
        }

        return false;
    }

    private function build_detection_text($question, array $history)
    {
        if (! $this->should_use_history_context($question, $history)) {
            return $this->augment_query_with_aliases((string) $question);
        }

        $parts = array($question);

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

        return $this->augment_query_with_aliases(implode("\n", array_reverse($parts)));
    }

    private function phrase($reply_language, $de, $en)
    {
        return 'English' === $reply_language ? $en : $de;
    }

    private function detect_reply_language($preferred_language, $question, array $history)
    {
        $explicit = $this->detect_requested_reply_language($question);
        if ('English' === $explicit || 'Deutsch' === $explicit) {
            return $explicit;
        }

        $sample = $this->knowledge_base->normalize_for_match((string) $question);

        if ($this->looks_like_english($sample)) {
            return 'English';
        }

        $preferred_language = strtolower(trim((string) $preferred_language));
        if (in_array($preferred_language, array('en', 'en-us', 'en-gb'), true)) {
            return 'English';
        }

        if (in_array($preferred_language, array('de', 'de-de', 'de_at', 'de-ch'), true)) {
            return 'Deutsch';
        }

        foreach (array_reverse($history) as $item) {
            if (($item['role'] ?? '') !== 'user') {
                continue;
            }

            $content = $this->knowledge_base->normalize_for_match((string) ($item['content'] ?? ''));
            if ('' !== $content) {
                return $this->looks_like_english($content) ? 'English' : 'Deutsch';
            }
        }

        return 'Deutsch';
    }

    private function looks_like_english($text)
    {
        $text = ' ' . $this->knowledge_base->normalize_for_match((string) $text) . ' ';
        if ('' === trim($text)) {
            return false;
        }

        $english_score = 0;
        $german_score = 0;

        $english_markers = array(
            ' the ', ' and ', ' what ', ' how ', ' can ', ' do ', ' does ', ' with ', ' for ', ' from ', ' school ',
            ' help ', ' assist ', ' interested ', ' application ', ' apply ', ' relocation ', ' flights ', ' thanks ',
            ' thank ', ' great ', ' learning ', ' german ', ' south africa ', ' kenya ', ' africa ', ' nursing ',
            ' nurse ', ' assistant ', ' accommodation ', ' hospital ', ' hospitals ', ' need ', ' needed ',
        );
        $german_markers = array(
            ' der ', ' die ', ' das ', ' und ', ' wie ', ' was ', ' kann ', ' koennen ', ' können ', ' bitte ',
            ' bewerbung ', ' bewerben ', ' ausbildung ', ' weiterbildung ', ' unterlagen ', ' kontakt ', ' termin ',
            ' deutsch ', ' pflege ', ' schule ', ' kosten ', ' hauptschulabschluss ', ' hallo ', ' mein ', ' meine ', ' aus ',
        );

        foreach ($english_markers as $marker) {
            if (false !== mb_strpos($text, $marker)) {
                $english_score++;
            }
        }

        foreach ($german_markers as $marker) {
            if (false !== mb_strpos($text, $marker)) {
                $german_score++;
            }
        }

        if (preg_match('/\b(i|i m|im|you|your|we|our|please|hello|good day|thanks)\b/u', trim($text))) {
            $english_score += 2;
        }

        return $english_score > $german_score && $english_score >= 1;
    }

    private function is_application_upload_problem_request($question)
    {
        $text = $this->knowledge_base->normalize_for_match((string) $question);
        if ('' === $text) {
            return false;
        }

        $mentions_application = $this->text_matches_patterns($text, array('bewerbung', 'bewerben', 'online bewerbung', 'application', 'apply', 'unterlagen', 'dokument', 'dokumente', 'documents'), false);
        $mentions_upload_problem = $this->text_matches_patterns($text, array('funktioniert nicht', 'geht nicht', 'kann nicht', 'nicht schicken', 'lassen sich nicht schicken', 'nicht hochladen', 'upload', 'hochladen', 'absenden', 'senden', 'send', 'submit', 'jpg', 'jpeg', 'datei', 'file'), false);

        return $mentions_application && $mentions_upload_problem;
    }

    private function build_application_upload_problem_answer($question, array $sources, $reply_language = 'Deutsch')
    {
        $mentions_jpg = $this->text_matches_patterns($question, array('jpg', 'jpeg'), false);

        if ('English' === $reply_language) {
            $answer = $mentions_jpg
                ? 'JPG files are normally suitable, but the upload can fail if the file is too large, the extension is unusual, or several files are uploaded at once. Please try one smaller JPG or convert the documents to PDF.'
                : 'If the online application or document upload does not work, please check whether all required fields are filled in and whether the files are accepted in type and size.';
            $answer .= ' If it still does not work, please use the contact form or send your application documents by email if that option is shown on the website.';
            if (! empty($sources[0]['url'])) {
                $answer .= ' Contact: ' . $sources[0]['url'];
            }

            return $answer;
        }

        $answer = $mentions_jpg
            ? 'JPG-Dateien sind grundsaetzlich naheliegend, aber der Upload kann scheitern, wenn die Datei zu gross ist, die Endung ungewoehnlich ist oder mehrere Dateien gleichzeitig hochgeladen werden. Bitte teste eine kleinere JPG-Datei oder wandle die Unterlagen in ein PDF um.'
            : 'Wenn die Online-Bewerbung oder der Upload der Unterlagen nicht funktioniert, pruefe bitte kurz, ob alle Pflichtfelder ausgefuellt sind und ob Dateityp und Dateigroesse akzeptiert werden.';
        $answer .= ' Wenn es weiterhin nicht klappt, nutze bitte das Kontaktformular oder schicke die Bewerbungsunterlagen per E-Mail, sofern diese Option auf der Website genannt ist.';
        if (! empty($sources[0]['url'])) {
            $answer .= ' Kontakt: ' . $sources[0]['url'];
        }

        return $answer;
    }

    private function is_apprentio_access_request($question)
    {
        $text = $this->knowledge_base->normalize_for_match((string) $question);
        if ('' === $text) {
            return false;
        }

        if ($this->is_clear_apprentio_access_problem($text)) {
            return true;
        }

        return $this->text_matches_patterns($text, array('traeger', 'träger'), false) &&
            $this->text_matches_patterns($text, array('zugang', 'zugangslink', 'login', 'reinkommt', 'nicht rein', 'access'), false);
    }

    private function build_apprentio_access_answer(array $sources, $reply_language = 'Deutsch')
    {
        $answer = $this->phrase(
            $reply_language,
            'Wenn ein Traeger nicht in APPRENTIO reinkommt oder keinen Zugangslink erhalten hat, nimm bitte direkt Kontakt mit der Schule oder dem zustaendigen Ansprechpartner auf.',
            'If a training provider cannot access APPRENTIO or has not received an access link, please contact the school or the responsible contact person directly.'
        );

        if (! empty($sources[0]['url'])) {
            $answer .= $this->phrase($reply_language, ' Am passendsten ist diese Seite: ', ' The best page is: ') . $sources[0]['url'];
        }

        return $answer;
    }

    private function is_known_requirement_question($question)
    {
        $text = $this->knowledge_base->normalize_for_match((string) $question);
        if ('' === $text) {
            return false;
        }

        if ($this->is_recognition_or_document_timing_question($text) || $this->is_language_certificate_timing_question($text)) {
            return false;
        }

        if ($this->text_matches_patterns($text, array('hauptschulabschluss klasse neun', 'hauptschulabschluss klasse 9', 'klasse neun', 'klasse 9'), false) && $this->text_matches_patterns($text, array('pflegefachfrau', 'pflegefachmann', 'pflegefachkraft'), false)) {
            return true;
        }

        if ($this->is_language_level_reply($text) && $this->text_matches_patterns($text, array('pflegefachassistenz', 'pflegefachassistentin', 'pflegeassistentin', 'pflegeassistenz', 'ausbildung'), false)) {
            return true;
        }

        if ($this->is_language_level_reply($text) && $this->text_matches_patterns($text, array('zertifikat', 'muendlich', 'mündlich', 'certificate', 'oral'), false)) {
            return true;
        }

        return false;
    }

    private function get_known_requirement_sources($question)
    {
        $text = $this->knowledge_base->normalize_for_match((string) $question);
        if ($this->text_matches_patterns($text, array('pflegefachfrau', 'pflegefachmann', 'pflegefachkraft'), false)) {
            return $this->knowledge_base->find_reference_routes('3 jaehrige pflegefachkraft', 2, 'training');
        }

        if ($this->text_matches_patterns($text, array('pflegefachassistenz', 'pflegefachassistentin', 'pflegeassistentin', 'pflegeassistenz'), false)) {
            return $this->knowledge_base->find_reference_routes('1 jaehrige pflegefachassistenz', 2, 'training');
        }

        return $this->knowledge_base->get_contact_links();
    }

    private function build_known_requirement_answer($question, array $sources, $reply_language = 'Deutsch')
    {
        $text = $this->knowledge_base->normalize_for_match((string) $question);
        $url = ! empty($sources[0]['url']) ? $sources[0]['url'] : '';

        if ($this->text_matches_patterns($text, array('hauptschulabschluss klasse neun', 'hauptschulabschluss klasse 9', 'klasse neun', 'klasse 9'), false)) {
            $answer = $this->phrase(
                $reply_language,
                'Fuer die 3-jaehrige Pflegefachkraft nennt die Seite mindestens Hauptschulabschluss Klasse 10. Alternativ ist Hauptschulabschluss Klasse 9 mit erfolgreich abgeschlossener einjaehriger Ausbildung zur Pflegefachassistenz genannt.',
                'For the 3-year nursing qualification, the page lists at least a German Hauptschulabschluss after class 10. As an alternative, it lists class 9 plus a successfully completed 1-year nursing assistant qualification.'
            );
        } elseif (preg_match('/\bb1\b/u', $text) && $this->text_matches_patterns($text, array('pflegefachassistenz', 'pflegefachassistentin', 'pflegeassistentin', 'pflegeassistenz'), false)) {
            $answer = $this->phrase(
                $reply_language,
                'Fuer die 1-jaehrige Pflegefachassistenz nennt die Ausbildungsseite Deutschkenntnisse auf B2-Niveau. Mit B1 solltest du dich trotzdem direkt beraten lassen, weil es auch Sprachfoerderung und internationale Wege geben kann.',
                'For the 1-year nursing assistant programme, the training page lists German language skills at B2 level. With B1, you should still ask directly for advice, because language support and international pathways may be relevant.'
            );
        } elseif ($this->is_language_level_reply($text)) {
            $answer = $this->phrase(
                $reply_language,
                'Die Seite nennt das erforderliche Sprachniveau, aber ob ein nur muendliches Zertifikat ausreicht, kann ich hier nicht verbindlich bestaetigen. Bitte reiche die Information bei der Bewerbung mit ein oder frage direkt ueber den Kontaktweg nach.',
                'The page lists the required language level, but I cannot reliably confirm here whether an oral-only certificate is sufficient. Please include that information with your application or ask directly through the contact channel.'
            );
        } else {
            $answer = $this->phrase(
                $reply_language,
                'Dazu finde ich eine passende Ausbildungsseite, aber die Einzelfallpruefung muss direkt ueber Bewerbung oder Kontakt laufen.',
                'I can point you to the matching training page, but the individual check has to be done through the application or contact channel.'
            );
        }

        if ('' !== $url) {
            $answer .= $this->phrase($reply_language, ' Details findest du hier: ', ' Details are here: ') . $url;
        }

        return $answer;
    }

    private function is_greeting_only($question)
    {
        $normalized = $this->knowledge_base->normalize_for_match((string) $question);
        if ('' === $normalized) {
            return false;
        }

        $greetings = array(
            'hallo',
            'hi',
            'hey',
            'moin',
            'guten tag',
            'guten morgen',
            'guten abend',
            'hello',
            'good morning',
            'good afternoon',
            'good evening',
        );

        return in_array($normalized, $greetings, true);
    }

    private function is_simple_acknowledgement($question)
    {
        $normalized = $this->knowledge_base->normalize_for_match((string) $question);
        if ('' === $normalized || false !== mb_strpos((string) $question, '?')) {
            return false;
        }

        $word_count = count(array_filter(preg_split('/\s+/u', $normalized)));
        if ($word_count > 5) {
            return false;
        }

        $acknowledgements = array(
            'ok',
            'okay',
            'alles klar',
            'danke',
            'danke schoen',
            'dankeschoen',
            'vielen dank',
            'super',
            'perfekt',
            'sehr gut',
            'thank you',
            'thanks',
            'okay thank you',
            'ok thank you',
            'very good',
            'great',
        );

        return in_array($normalized, $acknowledgements, true);
    }

    private function build_acknowledgement_answer($question, $reply_language = 'Deutsch')
    {
        $normalized = $this->knowledge_base->normalize_for_match((string) $question);

        if ('English' === $reply_language || $this->text_matches_patterns($normalized, array('thank you', 'thanks', 'very good', 'great'), false)) {
            return 'You are welcome. If you need anything else about courses, applications, locations or contact options, just ask.';
        }

        return 'Sehr gern. Wenn du noch etwas zu Kursen, Bewerbung, Standorten oder Kontakt wissen moechtest, frag einfach weiter.';
    }

    private function build_greeting_answer($question, $reply_language = 'Deutsch')
    {
        if ('English' === $reply_language || 'English' === $this->detect_requested_reply_language($question)) {
            return 'Hello :) How can I help you with our courses, applications, locations, or contact options?';
        }

        return 'Hallo :) Wobei kann ich dir zu Ausbildungen, Weiterbildungen, Standorten oder Kontakt weiterhelfen?';
    }

    private function detect_requested_reply_language($question)
    {
        $normalized = $this->knowledge_base->normalize_for_match((string) $question);

        if (preg_match('/\b(english|auf englisch|bitte englisch|englisch bitte|answer in english|reply in english|switch to english)\b/u', $normalized)) {
            return 'English';
        }

        if (preg_match('/\b(deutsch|auf deutsch|bitte deutsch|deutsch bitte|answer in german|reply in german|switch to german)\b/u', $normalized)) {
            return 'Deutsch';
        }

        if (preg_match('/\b(hello|good morning|good afternoon|good evening)\b/u', $normalized)) {
            return 'English';
        }

        if (preg_match('/\b(hallo|guten morgen|guten tag|guten abend)\b/u', $normalized)) {
            return 'Deutsch';
        }

        return null;
    }

    private function build_high_value_intent_answer($question, array $history, $reply_language = 'Deutsch')
    {
        $text = $this->knowledge_base->normalize_for_match((string) $question);
        if ('' === $text) {
            return null;
        }

        if ($this->is_clear_apprentio_access_problem($text)) {
            return null;
        }

        // Off-topic handled by OpenAI via theme_guardrail system prompt instruction.

        $contact_sources = $this->knowledge_base->get_contact_links();
        $pflegeschule_sources = $this->knowledge_base->find_reference_routes('kursangebote pflegeschule', 2, 'overview');
        $pflegefachkraft_sources = $this->knowledge_base->find_reference_routes('3 jaehrige pflegefachkraft', 2, 'training');
        $pflegeassistenz_sources = $this->knowledge_base->find_reference_routes('1 jaehrige pflegefachassistenz', 2, 'training');
        $international_sources = $this->knowledge_base->find_reference_routes('internationale pflegekraefte care connect', 2, 'international');
        $bildung_sources = $this->knowledge_base->find_reference_routes('kursangebote bildung karriere tagesseminare', 2, 'overview');
        $behandlungspflege_sources = $this->knowledge_base->find_reference_routes('behandlungspflege weiterbildung', 2, 'training');
        $betreuungskraft_sources = $this->knowledge_base->find_reference_routes('betreuungskraft 43b sgb xi weiterbildung', 2, 'training');

        // Verkuerzte Ausbildung nach PFA-Abschluss
        if ($this->text_matches_patterns($text, array('verkuerzt', 'verkürzt', 'angerechnet', 'anrechnung', 'anerkannt', 'zeitverkuerz', 'direkt einsteigen', 'direkt in die', 'nach der pflegefachassistenz', 'nach abschluss', 'nach dem abschluss'), false)
            && $this->text_matches_patterns($text, array('pflegefachkraft', 'pflegefachmann', 'pflegefachfrau', 'pflegefachassistenz', 'pfa', 'ausbildung'), false)) {
            $answer = $this->phrase(
                $reply_language,
                'Ja, nach erfolgreichem Abschluss der 1-jaehrigen Pflegefachassistenz kann die Ausbildungszeit zur Pflegefachkraft verkuerzt werden — in der Regel wird ein Jahr angerechnet, sodass die Ausbildung 2 statt 3 Jahre dauert. Die genauen Voraussetzungen und Eintrittstermine erfaehrt du direkt bei uns. Am besten nutze das Kontaktformular oder ruf uns an.',
                'Yes, after successfully completing the 1-year nursing assistant programme (Pflegefachassistenz), the training period for the full nursing qualification (Pflegefachkraft) can be shortened — typically one year is credited, reducing the programme from 3 to 2 years. For exact entry requirements and dates, please contact us directly via the contact form or phone.'
            );
            return $this->intent_result($answer, $this->merge_answer_sources($pflegefachkraft_sources, $pflegeassistenz_sources, $contact_sources), 1.6);
        }

        // Stellenangebote-Direktrouting
        if ($this->text_matches_patterns($text, array('stellenangebot', 'stellenangebote', 'offene stelle', 'offene stellen', 'arbeiten bei', 'ihr sucht', 'sucht ihr', 'habt ihr stellen', 'gibt es stellen', 'wie sind eure stellen', 'wie sind ihre stellen'), false)) {
            $job_sources = $this->knowledge_base->find_reference_routes('stellenangebote arbeiten', 2, 'page');
            $answer = $this->phrase(
                $reply_language,
                'Unsere aktuellen Stellenangebote findest du direkt auf der Stellenseite. Dort sind alle offenen Positionen aufgelistet — Lehrkraefte, Dozenten und weitere Stellen.',
                'You can find our current job openings directly on the jobs page. All open positions are listed there — teaching staff, lecturers and other roles.'
            );
            if (! empty($job_sources[0]['url'])) {
                $answer .= ' ' . $job_sources[0]['url'];
            } else {
                $answer .= ' https://aschke-seminare.de/kontakt-2/';
            }
            return $this->intent_result($answer, $job_sources, 1.5);
        }

        // (Keyword-Direktrouter entfernt — OpenAI soll Ausbildungsanfragen mit
        //  echtem Seiteninhalt beantworten statt statischer Platzhalterphrases)

        // Quereinsteiger → Betreuungskraft (spezifisch)
        if ($this->text_matches_patterns($text, array('quereinsteiger', 'quereinstieg', 'berufswechsel', 'umsteiger', 'kein pflege', 'keine pflege', 'keine ausbildung', 'ohne ausbildung', 'ohne pflegeausbildung'), false)
            && $this->text_matches_patterns($text, array('betreuungskraft', 'alltagsbegleiter', 'alltagsbegleiterin', '43b', 'betreuung'), false)) {
            $answer = $this->phrase(
                $reply_language,
                'Ja, als Quereinsteiger kannst du die Qualifizierung zur Betreuungskraft nach Par. 43b SGB XI machen — dafuer ist keine Pflegevorausbildung erforderlich. Die Qualifizierung dauert in der Regel einige Wochen und vermittelt die notwendigen Kenntnisse fuer die Arbeit als Betreuungskraft in der Pflege. Alle Infos zur Qualifizierung findest du hier.',
                'Yes, as a career changer you can complete the Betreuungskraft qualification under § 43b SGB XI — no prior nursing qualification is required. The training usually takes a few weeks and provides the knowledge needed to work as a care assistant. All details about the qualification are here.'
            );
            return $this->intent_result($answer, $betreuungskraft_sources, 1.5);
        }

        // Quereinsteiger / Berufserfahrung / Anerkennung (allgemein)
        if ($this->text_matches_patterns($text, array('berufserfahrung', 'quereinsteiger', 'quereinstieg', 'anerkennung', 'anerkannt', 'anerkennnung', 'vorher gearbeitet', 'schon gearbeitet', 'bereits gearbeitet', 'abschluss in', 'studium', 'examen', 'fachangestellte', 'medizinische fachangestellte', 'krankenschwester', 'muss ich trotzdem', 'muss ich noch', 'muss ich die', 'brauche ich noch die ausbildung', 'ausbildung noch machen', 'vollausbildung'), false) && $this->is_training_or_application_request($text)) {
            $answer = $this->phrase(
                $reply_language,
                'Das kommt auf deine bisherige Qualifikation und ob sie in Deutschland anerkannt ist an. Vorerfahrungen aus medizinischen oder pflegerischen Berufen koennen je nach Fall beruecksichtigt werden, befreien aber in der Regel nicht vollstaendig von der Ausbildung. Fuer eine individuelle Einschaetzung wende dich bitte direkt an unser Team — am schnellsten ueber das Kontaktformular oder per Telefon.',
                'This depends on your existing qualification and whether it is recognised in Germany. Prior experience in medical or care professions can be taken into account in some cases, but does not generally exempt you from the full training. For an individual assessment, please contact our team directly — fastest via the contact form or by phone.'
            );
            return $this->intent_result($answer, $contact_sources, 1.4);
        }

        // Freie Plätze / Verfügbarkeit
        if ($this->text_matches_patterns($text, array('freie plaetze', 'freie platze', 'noch plaetze', 'noch platze', 'platze frei', 'platze noch frei', 'freier platz', 'platz frei', 'noch platz', 'available spots', 'free spots', 'available places', 'places left', 'spots left'), false)) {
            $answer = $this->phrase(
                $reply_language,
                'Die aktuell verfuegbaren Plaetze aendern sich laufend durch Zu- und Absagen, daher kann ich dir hier keine verbindliche Zahl nennen. Ruf am besten direkt bei uns an — das Team kann dir sofort sagen, ob und fuer welche Ausbildung noch Plaetze frei sind.',
                'Available places change frequently due to sign-ups and cancellations, so I cannot give you a reliable number here. Please call us directly — the team can tell you straight away whether places are still available.'
            );
            $answer_sources = $this->merge_answer_sources($pflegeschule_sources, $contact_sources);

            return $this->intent_result($answer, $answer_sources, 1.3);
        }

        // Teilzeit-Ausbildung
        if ($this->text_matches_patterns($text, array('teilzeit', 'part time', 'part-time', 'halbzeit', 'halbtags', 'halbtag', 'halbe stelle', 'reduziert', 'weniger stunden'), false) && $this->is_training_or_application_request($text)) {
            $answer = $this->phrase(
                $reply_language,
                'Ob die Ausbildung in Teilzeit moeglich ist, muss fuer deinen konkreten Wunsch direkt mit der Schule abgeklaert werden — gesetzlich gibt es unter bestimmten Voraussetzungen eine Teilzeitoption, aber die Umsetzung haengt vom Einzelfall ab. Wende dich am besten ueber das Kontaktformular an das Team.',
                'Whether training is possible on a part-time basis needs to be clarified directly with the school for your specific situation — legally there is a part-time option under certain conditions, but implementation depends on the individual case. Please contact the team via the contact form.'
            );
            return $this->intent_result($answer, $this->merge_answer_sources($pflegeschule_sources, $contact_sources), 1.3);
        }

        // Online-Ausbildung / Fernstudium (nicht: Deutschkurs online oder Sprachkurs)
        if ($this->text_matches_patterns($text, array('online', 'fernunterricht', 'fernstudium', 'digital', 'remote', 'distance', 'zuhause', 'von zuhause', 'von zu hause'), false)
            && ! $this->text_matches_patterns($text, array('deutsch lernen', 'deutschkurs', 'sprachkurs', 'deutschunterricht', 'sprachunterricht', 'deutsch online', 'online deutsch', 'b1', 'b2', 'a1', 'a2', 'c1', 'c2', 'deutschkenntnisse', 'sprachkenntnisse'), false)
            && $this->is_training_or_application_request($text)) {
            $answer = $this->phrase(
                $reply_language,
                'Die Ausbildungen bei uns finden in Praesenz statt — Unterricht an der Pflegeschule und praktische Einsaetze in Pflegeeinrichtungen. Eine rein online oder per Fernstudium absolvierbare Pflegeausbildung gibt es bei uns nicht. Details zur Ausbildung findest du hier.',
                'Our training programmes are in-person — classes at the nursing school and practical placements in care facilities. A purely online or distance-learning nursing qualification is not available. You can find details about the programme here.'
            );
            $answer_sources = $this->merge_answer_sources($pflegeschule_sources, $contact_sources);

            return $this->intent_result($answer, $answer_sources, 1.3);
        }

        // Bewerbungsbestaetigung / Rueckmeldung nach Bewerbung
        if ($this->text_matches_patterns($text, array('beworben'), false) && $this->text_matches_patterns($text, array('email', 'e-mail', 'mail', 'bestaetigung', 'bestätigung', 'rueckmeldung', 'rückmeldung', 'antwort', 'erhalte', 'erhalt', 'wie lange', 'wann melde', 'wann erhalte'), false)) {
            $answer = $this->phrase(
                $reply_language,
                'Nach einer eingegangenen Bewerbung meldet sich das Team so bald wie moeglich bei dir. Die Rueckmeldung erfolgt in der Regel per E-Mail oder telefonisch. Falls du laenger als erwartet nichts gehoert hast, kannst du gern ueber das Kontaktformular nachfragen.',
                'After receiving your application, the team will get back to you as soon as possible. Feedback is usually given by email or phone. If you have not heard back after longer than expected, please follow up through the contact form.'
            );

            return $this->intent_result($answer, $contact_sources, 1.2);
        }

        // Ansprechpartner / Kontaktperson / "wer ist zustaendig"
        if ($this->text_matches_patterns($text, array('ansprechpartner', 'ansprechpartnerin', 'ansprechperson', 'zustaendig', 'wer ist zustandig', 'wer ist verantwortlich', 'wer hilft', 'wer kann helfen', 'wer ist da', 'wen kann ich fragen', 'wen soll ich kontaktieren', 'wer bearbeitet', 'wer kuemmert', 'kontaktperson', 'who is responsible', 'who should i contact', 'who can help', 'dame mit der nummer', 'herr', 'frau'), false)) {
            $answer = $this->phrase(
                $reply_language,
                'Fuer alle Fragen rund um Ausbildung, Bewerbung und Kurse erreichst du unser Team direkt ueber das Kontaktformular auf der Website oder telefonisch. Die passenden Kontaktdaten findest du hier.',
                'For all questions about training, applications and courses you can reach our team directly via the contact form on the website or by phone. You can find the contact details here.'
            );
            return $this->intent_result($answer, $contact_sources, 1.5);
        }

        // Auslaender / auslaendisch / Zugaenglichkeit fuer Auslaender (ohne spezifischen Auslandsbezug im Intent)
        if ($this->text_matches_patterns($text, array('auslaender', 'auslander', 'auslaendisch', 'auslandisch', 'aufgeschlossen', 'fuer auslaender', 'fur auslander', 'for foreigners', 'foreigners', 'migrants', 'migration', 'marokko', 'marooko', 'morocco', 'tunesien', 'algerien', 'libanon', 'syrien', 'irak', 'iran', 'afghanistan', 'pakistan', 'tuerkei', 'tuerkisch', 'arabisch', 'nigeria', 'ghana', 'philippinen', 'vietnam', 'rumaenien', 'bulgarien', 'kosovo', 'albanien', 'serbien', 'bosnien', 'aus dem ausland', 'aus ausland', 'nicht aus deutschland', 'kein deutsch', 'kein deutscher', 'keine deutsche', 'auslaendisch', 'heimatland', 'ich komme aus', 'i am from', 'i come from', 'from my country', 'south africa', 'kenya', 'senegal', 'kamerun', 'cameroon', 'aegypten', 'egypt', 'indien', 'india', 'sri lanka', 'bangladesch', 'nepal', 'indonesien', 'kolumbien', 'brasilien'), false)) {
            $answer = $this->phrase(
                $reply_language,
                'Fuer Bewerberinnen und Bewerber aus dem Ausland ist AschkeCare-Connect der richtige erste Anlaufpunkt — dort gibt es Unterstuetzung bei Bewerbung, Anerkennung und den naechsten Schritten: https://aschkecare-connect.de',
                'For applicants from abroad, AschkeCare-Connect is the right first point of contact — it covers application, recognition and the next steps: https://aschkecare-connect.de'
            );
            $answer_sources = $this->merge_answer_sources($international_sources, $pflegeschule_sources);

            return $this->intent_result($answer, $answer_sources, 1.4);
        }

        if ($this->text_matches_patterns($text, array('tagungsraum', 'tagungsraeume', 'seminarraum', 'seminarraeume', 'konferenzraum', 'veranstaltungsraum', 'raummiete', 'raum buchen', 'raeume buchen', 'raum mieten', 'raeume mieten', 'alte schraubenfabrik', 'schraubenfabrik', 'gesundheitscampus'), false)) {
            $is_campus = $this->text_matches_patterns($text, array('gesundheitscampus', 'campus'), false);
            $answer = $this->phrase(
                $reply_language,
                'Tagungsraeume und Seminarraeume findest du in der Alten Schraubenfabrik Hagen — alle Infos und die Buchungsmoeglichkeit gibt es direkt hier: https://aschke-seminare.de/' . ($is_campus ? 'gesundheitscampus-hagen/' : 'gesundheitscampus-hagen/'),
                'Conference and seminar rooms are available at the Alte Schraubenfabrik Hagen — all information and booking options are here: https://aschke-seminare.de/gesundheitscampus-hagen/'
            );
            $room_sources = $this->knowledge_base->find_reference_routes('gesundheitscampus tagungsraum', 2, 'page');
            return $this->intent_result($answer, $room_sources, 1.4);
        }

        // Schulabschluss-Voraussetzungen (ESA / Erster Schulabschluss / Hauptschule allgemein)
        if ($this->text_matches_patterns($text, array('esa', 'erster schulabschluss', 'ersten schulabschluss', 'hauptschulabschluss', 'hauptschule', 'schulabschluss', 'realschulabschluss', 'abitur', 'fachhochschulreife', 'mittlerer abschluss'), false) && $this->is_training_or_application_request($text)) {
            $is_fachkraft = $this->text_matches_patterns($text, array('pflegefachfrau', 'pflegefachmann', 'pflegefachkraft', '3 jahr', 'drei jahr', 'dreijaehrig'), false);
            $is_assistenz = $this->text_matches_patterns($text, array('pflegefachassistenz', 'pflegeassistenz', 'pflegeassistentin', 'pflegefachassistent', 'pflegehelfer', '1 jahr', 'einjaehrig'), false);

            if ($is_fachkraft) {
                $sources = $pflegefachkraft_sources;
                $answer = $this->phrase(
                    $reply_language,
                    'Fuer die 3-jaehrige Ausbildung zur Pflegefachkraft ist mindestens ein Hauptschulabschluss der Klasse 10 erforderlich. Alternativ gilt: Hauptschulabschluss Klasse 9 plus erfolgreich abgeschlossene 1-jaehrige Pflegefachassistenz. Ein ESA (Erster Schulabschluss / Klasse 9) allein reicht in der Regel nicht direkt fuer die Pflegefachkraft-Ausbildung. Details findest du auf der Ausbildungsseite.',
                    'For the 3-year nursing qualification (Pflegefachkraft), at least a Hauptschulabschluss after class 10 is required. Alternatively: class 9 certificate plus a completed 1-year nursing assistant qualification. A class 9 certificate alone is generally not sufficient for direct entry into the 3-year programme. Details are on the training page.'
                );
            } elseif ($is_assistenz) {
                $sources = $pflegeassistenz_sources;
                $answer = $this->phrase(
                    $reply_language,
                    'Fuer die 1-jaehrige Pflegefachassistenz sind die genauen Zugangsvoraussetzungen auf der Ausbildungsseite hinterlegt. Ob ein ESA (Erster Schulabschluss) ausreicht, haengt vom Einzelfall ab — bitte frag direkt ueber das Kontaktformular nach, damit das Team das fuer dich pruefen kann.',
                    'For the 1-year nursing assistant programme, the exact entry requirements are listed on the training page. Whether a class 9 certificate (ESA) is sufficient depends on the individual case — please ask through the contact form so the team can check this for you.'
                );
            } else {
                $sources = $this->merge_answer_sources($pflegeschule_sources, $contact_sources);
                $answer = $this->phrase(
                    $reply_language,
                    'Welcher Schulabschluss benoetigt wird, haengt von der gewuenschten Ausbildung ab. Fuer die 3-jaehrige Pflegefachkraft ist mindestens Hauptschulabschluss Klasse 10 erforderlich; fuer die 1-jaehrige Pflegefachassistenz stehen die Details auf der jeweiligen Ausbildungsseite. Bei einem ESA (Klasse 9) empfehle ich, direkt anzufragen — das Team klaert das individuell.',
                    'The required qualification depends on which training programme you choose. For the 3-year nursing qualification at least a class 10 certificate is needed; for the 1-year assistant programme the details are on the respective training page. With a class 9 certificate (ESA) I recommend asking directly — the team will clarify this individually.'
                );
            }

            return $this->intent_result($answer, $sources, 1.3);
        }

        if ($this->is_minijob_during_training_question($text)) {
            $sources = $this->merge_answer_sources($pflegeschule_sources, $contact_sources);
            $answer = $this->phrase(
                $reply_language,
                'Zu einem Minijob neben der Ausbildung habe ich keine verbindliche Einzelfallregel in den hinterlegten Informationen. Bitte stimme einen Nebenjob vorher mit Ausbildungsbetrieb/Traeger und Schule ab; wichtig ist, dass Unterricht, Praxis und die Ausbildungszeiten nicht beeintraechtigt werden.',
                'I do not have a binding individual rule in the stored information about having a mini-job alongside training. Please coordinate any side job with the training provider/employer and the school first; lessons, practical training and training hours must not be affected.'
            );

            return $this->intent_result($answer, $sources, 1.35);
        }

        if ($this->text_matches_patterns($text, array('email adresse', 'e mail adresse', 'mail adresse', 'email von der schule', 'e mail von der schule'), false)) {
            $answer = $this->phrase(
                $reply_language,
                'Die verlaessliche Kontaktmoeglichkeit findest du auf der Kontaktseite der Schule. Dort steht der aktuell gepflegte Kontaktweg.',
                'The reliable contact option is on the school contact page. That page contains the current contact route.'
            );

            return $this->intent_result($answer, $contact_sources, 1.2);
        }

        if ($this->text_matches_patterns($text, array('fsj', 'freiwilliges soziales jahr'), false)) {
            $answer = $this->phrase(
                $reply_language,
                'Zu FSJ-Stellen oder FSJ-Programmen habe ich in den hinterlegten Informationen keinen sicheren Eintrag. Bitte frage dazu direkt ueber das Kontaktformular nach, damit das Team die aktuelle Lage fuer 2026 pruefen kann.',
                'I do not have a reliable entry for FSJ positions or FSJ programmes in the stored information. Please ask through the contact form so the team can check the current situation for 2026.'
            );

            return $this->intent_result($answer, $contact_sources, 1.1);
        }

        if ($this->text_matches_patterns($text, array('bewertungsfrist', 'bewertungsfristen'), false)) {
            $answer = $this->phrase(
                $reply_language,
                'Zu Bewertungsfristen finde ich gerade keinen sicheren Eintrag auf den passenden Seiten. Bitte nutze dafuer das Kontaktformular, damit du keine falsche Frist genannt bekommst.',
                'I cannot find a reliable entry for assessment deadlines on the matching pages right now. Please use the contact form so you do not receive an incorrect deadline.'
            );

            return $this->intent_result($answer, $contact_sources, 1.1);
        }

        if ($this->text_matches_patterns($text, array('arbeitsentgeltzuschuss', 'entgeltzuschuss', 'foerderung arbeitsentgelt', 'förderung arbeitsentgelt'), false)) {
            $sources = $this->merge_answer_sources($bildung_sources, $contact_sources);
            $answer = $this->phrase(
                $reply_language,
                'Zum Arbeitsentgeltzuschuss kann ich hier keine verbindliche Einzelfallauskunft geben. Fuer Foerder- oder Kostenthemen ist der direkte Kontakt der richtige Weg; fuer passende Qualifizierungen kannst du zusaetzlich die Kursuebersicht nutzen.',
                'I cannot give a binding individual answer about wage subsidies here. For funding or cost questions, direct contact is the right route; for matching qualifications, you can also use the course overview.'
            );

            return $this->intent_result($answer, $sources, 1.1);
        }

        if ($this->text_matches_patterns($text, array('praeventionskurs', 'präventionskurs', 'preventionskurs', 'rueckenschule', 'rückenschule', 'ruecken', 'rücken'), false)) {
            $sources = $this->merge_answer_sources($bildung_sources, $contact_sources);
            $answer = $this->phrase(
                $reply_language,
                'Zu Praeventionskursen, Rueckenschule oder kleinen Ruecken-Schulungen finde ich keinen eindeutigen eigenen Kurseintrag. Am besten nutzt du die Kursuebersicht Bildung & Karriere oder fragst den konkreten Bedarf direkt ueber das Kontaktformular an.',
                'I cannot find a clear dedicated course entry for prevention courses, back-school training or small back-care sessions. Please use the education and career course overview or ask about the concrete need through the contact form.'
            );

            return $this->intent_result($answer, $sources, 1.15);
        }

        if ($this->text_matches_patterns($text, array('externpruefung', 'externprüfung'), false)) {
            $sources = $this->merge_answer_sources($bildung_sources, $contact_sources);
            $answer = $this->phrase(
                $reply_language,
                'Zur Externpruefung finde ich keinen sicheren eigenen Kurseintrag. Wenn du viel Pflegeerfahrung hast, frage bitte direkt ueber das Kontaktformular nach, welche Anerkennungs- oder Qualifizierungswege fuer dich passen.',
                'I cannot find a reliable dedicated entry for an external examination. If you have substantial care experience, please ask through the contact form which recognition or qualification route fits your situation.'
            );

            return $this->intent_result($answer, $sources, 1.15);
        }

        if ($this->text_matches_patterns($text, array('traeger selber aussuchen', 'träger selber aussuchen', 'traeger selbst aussuchen', 'träger selbst aussuchen', 'mit welchen traegern', 'mit welchen trägern', 'welche traeger', 'welche träger'), false)) {
            $sources = $this->merge_answer_sources($pflegeschule_sources, $contact_sources);
            $answer = $this->phrase(
                $reply_language,
                'Zur Auswahl oder Liste der Traeger kann ich hier keine verbindliche Aussage machen. Fuer die Pflegeausbildung ist die Schule der richtige Ansprechpartner; dort bekommst du die aktuelle Auskunft, ob du einen Traeger selbst einbringen kannst und mit welchen Partnern gearbeitet wird.',
                'I cannot give a binding answer here about choosing or listing training providers. For nursing training, the school is the right contact; they can tell you whether you can bring your own provider and which partners are currently involved.'
            );

            return $this->intent_result($answer, $sources, 1.2);
        }

        if ($this->text_matches_patterns($text, array('mittagspause', 'pausenzeiten', 'betreuung meines kindes', 'betreuung kind', 'kinderbetreuung'), false) && $this->text_matches_patterns($text, array('kurs', 'unterricht', 'schule', 'ausbildung', 'witten'), false)) {
            $answer = $this->phrase(
                $reply_language,
                'Konkrete Pausenzeiten fuer einen Kurs in Witten kann ich nicht sicher aus den hinterlegten Informationen nennen. Bitte frage direkt ueber das Kontaktformular mit dem Kursnamen nach, damit du die Kinderbetreuung verlaesslich planen kannst.',
                'I cannot reliably state the exact break times for a course in Witten from the stored information. Please ask through the contact form with the course name so you can plan childcare reliably.'
            );

            return $this->intent_result($answer, $contact_sources, 1.1);
        }

        if ($this->is_recognition_or_document_timing_question($text)) {
            $sources = $this->merge_answer_sources($international_sources, $contact_sources);
            $answer = $this->phrase(
                $reply_language,
                'Eine verbindliche Zusage zur Anerkennung von Abschluessen oder dazu, ob beglaubigte Uebersetzungen vorerst ausreichen, kann ich hier nicht geben. Wenn du dich aus dem Ausland bewirbst, ist AschkeCare-Connect der passende Einstieg; reiche deine vorhandenen Unterlagen dort ein oder frage direkt nach, welche Nachweise wann benoetigt werden.',
                'I cannot give a binding decision here on recognition of qualifications or whether certified translations are sufficient at first. If you apply from abroad, AschkeCare-Connect is the right starting point; submit the documents you already have there or ask directly which documents are needed and when.'
            );

            return $this->intent_result($answer, $sources, 1.35);
        }

        if ($this->is_language_certificate_timing_question($text)) {
            $sources = $this->merge_answer_sources($pflegeschule_sources, $contact_sources);
            $answer = $this->phrase(
                $reply_language,
                'Ob du das B2-Zertifikat nachreichen kannst, muss die Schule fuer deine konkrete Bewerbung bestaetigen. Du kannst die Bewerbung mit dem Hinweis auf den Pruefungstermin starten und direkt nachfragen, bis wann das Zertifikat vorliegen muss.',
                'Whether you can submit the B2 certificate later has to be confirmed by the school for your specific application. You can start the application with a note about the exam date and ask directly by when the certificate must be available.'
            );

            return $this->intent_result($answer, $sources, 1.25);
        }

        if ($this->is_language_course_or_certificate_question($text)) {
            $sources = $this->merge_answer_sources($international_sources, $pflegeschule_sources, $contact_sources);
            $answer = $this->phrase(
                $reply_language,
                'Ob du dich schon mit Grundkenntnissen bewerben kannst und das Sprachzertifikat spaeter nachreichst, muss fuer den konkreten Kurs bestaetigt werden. Wenn du dich aus dem Ausland bewirbst, nutze AschkeCare-Connect; dort passt die Frage zu Sprache, Bewerbung und naechsten Schritten am besten.',
                'Whether you can apply with basic German and submit the language certificate later has to be confirmed for the specific course. If you apply from abroad, please use AschkeCare-Connect; that is the best route for questions about language, application and next steps.'
            );

            return $this->intent_result($answer, $sources, 1.25);
        }

        if ($this->is_course_payment_question($text)) {
            $sources = $this->merge_answer_sources($this->pick_training_sources_for_query($text), $contact_sources);
            $answer = $this->phrase(
                $reply_language,
                'Ob du waehrend dieses konkreten Kurses eine Verguetung bekommst, kann ich aus den hinterlegten Informationen nicht sicher zusagen. Bitte pruefe die passende Kursseite und frage fuer deinen Kurs direkt nach.',
                'I cannot reliably confirm from the stored information whether you receive payment during this specific course. Please check the matching course page and ask directly for your course.'
            );

            return $this->intent_result($answer, $sources, 1.15);
        }

        if ($this->is_open_place_or_start_availability_question($text)) {
            $sources = $this->merge_answer_sources(
                $this->pick_training_sources_for_query($text),
                $this->is_nursing_training_from_abroad_question($text) ? $international_sources : array(),
                $contact_sources
            );
            if ($this->is_nursing_assistant_training_question($text)) {
                $answer = $this->phrase(
                    $reply_language,
                    'Fuer Pflegehelfer beziehungsweise Nursing Assistant passt am ehesten die 1-jaehrige Pflegefachassistenz. Ob fuer 2026 noch ein Platz frei ist, kann ich nicht live pruefen; die Ausbildungsseite ist der beste Einstieg, fuer eine verbindliche Platz-Auskunft frage bitte direkt nach.',
                    'For Pflegehelfer or nursing assistant, the closest matching programme is the 1-year nursing assistant training. I cannot check live whether a place is still available for 2026; the programme page is the best starting point, and for a binding availability answer please ask directly.'
                );
            } else {
                $answer = $this->phrase(
                    $reply_language,
                    'Ob fuer dieses Jahr noch ein Platz frei ist, kann ich nicht live pruefen. Die passende Kursseite ist der beste Einstieg fuer Termine und Bewerbung; fuer eine verbindliche Platz-Auskunft frage bitte direkt nach.',
                    'I cannot check live whether a place is still available this year. The matching course page is the best starting point for dates and application; for a binding availability answer, please ask directly.'
                );
            }

            return $this->intent_result($answer, $sources, 1.2);
        }

        if ($this->is_nursing_training_from_abroad_question($text)) {
            $sources = $this->merge_answer_sources($international_sources, $pflegeschule_sources, $contact_sources);
            $answer = $this->phrase(
                $reply_language,
                'Wenn du aus dem Ausland eine Pflegeausbildung beginnen moechtest, ist AschkeCare-Connect der passende Einstieg fuer internationale Bewerbungen. Dort geht es um Bewerbung, Sprache und die naechsten Schritte; die allgemeine Kursuebersicht der Pflegeschule zeigt dir zusaetzlich die Ausbildungsangebote.',
                'If you want to start nursing training from abroad, AschkeCare-Connect is the right starting point for international applications. It covers application, language and next steps; the nursing school course overview also shows the available programmes.'
            );

            return $this->intent_result($answer, $sources, 1.45);
        }

        return null;
    }

    private function intent_result($answer, array $sources, $confidence = 1)
    {
        return array(
            'answer' => $answer,
            'sources' => $this->prepare_sources($sources),
            'confidence' => $confidence,
        );
    }

    private function merge_answer_sources()
    {
        $merged = array();
        $seen = array();

        foreach (func_get_args() as $sources) {
            if (! is_array($sources)) {
                continue;
            }

            foreach ($sources as $source) {
                if (! is_array($source)) {
                    continue;
                }

                $url = trim((string) ($source['url'] ?? ''));
                $key = '' !== $url ? $url : (($source['type'] ?? '') . '|' . ($source['title'] ?? ''));
                if ('' === $key || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $merged[] = $source;
            }
        }

        return $merged;
    }

    private function pick_training_sources_for_query($text)
    {
        if ($this->is_nursing_assistant_training_question($text)) {
            return $this->knowledge_base->find_reference_routes('1 jaehrige pflegefachassistenz', 2, 'training');
        }

        if ($this->is_three_year_nursing_training_question($text)) {
            return $this->knowledge_base->find_reference_routes('3 jaehrige pflegefachkraft', 2, 'training');
        }

        return $this->knowledge_base->find_reference_routes('kursangebote pflegeschule', 2, 'overview');
    }

    private function is_recognition_or_document_timing_question($text)
    {
        $mentions_recognition = $this->text_matches_patterns($text, array('anerkennung', 'recognition', 'reconnaissance', 'diplom', 'diplome', 'diploma', 'abschluesse', 'abschlüsse', 'zeugnisse', 'beglaubigte uebersetzung', 'beglaubigte übersetzung', 'certified translation'), false);
        $mentions_application = $this->text_matches_patterns($text, array('bewerben', 'bewerbung', 'postuler', 'apply', 'application', 'ausbildungsvertrag', 'arbeitsvertrag', 'contract'), false);

        return $mentions_recognition && $mentions_application;
    }

    private function is_language_certificate_timing_question($text)
    {
        return $this->is_language_level_reply($text) &&
            $this->text_matches_patterns($text, array('zertifikat', 'certificate', 'pruefung', 'prüfung', 'nachreichen', 'spaeter', 'später', 'schicke', 'send', 'later', 'while in school', 'while in the school'), false);
    }

    private function is_language_course_or_certificate_question($text)
    {
        $mentions_language = $this->text_matches_patterns($text, array('german language', 'deutsch lernen', 'deutschkurs', 'sprachkurs', 'language train', 'language training', 'sprachzertifikat', 'language certificate', 'certificate later'), false);
        $mentions_course_or_application = $this->text_matches_patterns($text, array('apply', 'application', 'bewerben', 'bewerbung', 'course', 'kurs', 'school', 'schule', 'ausbildung', 'training'), false);

        return $mentions_language && $mentions_course_or_application;
    }

    private function is_course_payment_question($text)
    {
        return $this->text_matches_patterns($text, array('payment during', 'recieve payment', 'receive payment', 'paid during', 'verguetung', 'vergütung', 'bezahlt', 'gehalt', 'lohn'), false) &&
            $this->text_matches_patterns($text, array('course', 'kurs', 'ausbildung', 'training'), false);
    }

    private function is_open_place_or_start_availability_question($text)
    {
        $mentions_place = $this->text_matches_patterns($text, array('platz frei', 'freie plaetze', 'freie plätze', 'gibt platz', 'gibt es platz', 'opening', 'openings', 'available place', 'availability'), false);
        $mentions_time_or_school = $this->text_matches_patterns($text, array('dieses jahr', '2026', 'schule', 'course', 'kurs', 'ausbildung', 'training'), false);

        return $mentions_place && $mentions_time_or_school;
    }

    private function is_nursing_assistant_training_question($text)
    {
        // Aufstiegsfragen ("als Pflegefachassistenz zur Pflegefachkraft weiterbilden") gehören nicht hierher
        if ($this->text_matches_patterns($text, array('pflegefachkraft', 'pflegefachfrau', 'pflegefachmann'), false) &&
            $this->text_matches_patterns($text, array('weiterbilden', 'weiterbildung', 'aufsteigen', 'aufstieg', 'werden', 'wechseln', 'hoher', 'höher'), false)) {
            return false;
        }

        return $this->text_matches_patterns(
            $text,
            array('pflegehelfer', 'pflegehelferin', 'plegehelfer', 'nursing assistant', 'nurse assistant', 'pflegeassistenz', 'pflegeassistent', 'pflegeassistentin', 'pflegefachassistenz', '1 jaehrige', '1 jährige', 'einjaehrige', 'einjährige'),
            false
        );
    }

    private function is_three_year_nursing_training_question($text)
    {
        return $this->text_matches_patterns(
            $text,
            array('pflegefachfrau', 'pflegefachmann', 'pflegefachkraft', '3 jaehrige', '3 jährige', 'dreijaehrige', 'dreijährige'),
            false
        );
    }

    private function is_general_nursing_training_question($text)
    {
        return $this->text_matches_patterns($text, array('pflege ausbildung', 'pflegeausbildung', 'nursing training', 'nursing course', 'ausbildung in der pflege', 'ausbildung im pflegebereich'), false);
    }

    private function is_nursing_training_from_abroad_question($text)
    {
        return ($this->is_general_nursing_training_question($text) || $this->is_three_year_nursing_training_question($text) || $this->is_nursing_assistant_training_question($text)) &&
            ($this->question_explicitly_mentions_foreign_context($text) || $this->text_matches_patterns($text, array('pakistan', 'kamerun', 'cameroon', 'migrant', 'migrants', 'from abroad', 'abroad', 'ausland', 'high school diploma', 'baccalaureat', 'baccalauréat'), false));
    }

    private function is_minijob_during_training_question($text)
    {
        $mentions_side_job = $this->text_matches_patterns(
            $text,
            array('minijob', 'mini job', 'nebenjob', 'nebentaetigkeit', 'side job', 'part time job'),
            false
        );
        $mentions_training = $this->text_matches_patterns(
            $text,
            array('ausbildung', 'ausbild', 'schule', 'unterricht', 'kurs', 'training'),
            false
        );

        return $mentions_side_job && $mentions_training;
    }

    private function is_context_correction_reply($question)
    {
        $text = $this->knowledge_base->normalize_for_match((string) $question);
        if ('' === $text) {
            return false;
        }

        return $this->text_matches_patterns(
            $text,
            array('nicht meine frage', 'war nicht meine frage', 'falsch verstanden', 'nicht danach gefragt', 'meine frage war', 'ich meinte', 'ich meine', 'not my question', 'misunderstood', 'you misunderstood', 'i meant'),
            false
        );
    }

    private function is_clear_apprentio_access_problem($text)
    {
        return $this->text_matches_patterns($text, array('apprentio', 'zugangslink', 'access link', 'login', 'kein zugang', 'nicht reinkommt', 'nicht rein', 'log in'), false);
    }

    private function is_clearly_off_topic($text, $reply_language = 'Deutsch')
    {
        $off_topic_patterns = array(
            'wetter', 'weather', 'temperatur', 'regen', 'schnee', 'sonne', 'wolken', 'forecast',
            'fussball', 'bundesliga', 'sport', 'tennis', 'basketball', 'olympia',
            'rezept', 'kochen', 'backen', 'zutaten', 'kalorien', 'ernaehrung',
            'essen bestellen', 'pizza bestellen', 'lieferando', 'restaurant', 'takeaway',
            'politik', 'partei', 'wahl', 'bundestag', 'regierung', 'chancellor',
            'film', 'serie', 'netflix', 'kino', 'musik', 'konzert', 'album',
            'handy', 'smartphone', 'iphone', 'android', 'computer reparatur',
            'reise', 'urlaub', 'hotel', 'flug', 'booking', 'airbnb',
            'aktien', 'bitcoin', 'krypto', 'boerse', 'investition',
            'horoskop', 'sternzeichen', 'astrologie',
            'witze', 'witz', 'joke', 'schreib mir', 'dichten',
        );

        if (! $this->text_matches_patterns($text, $off_topic_patterns, false)) {
            return false;
        }

        $nursing_rescue_patterns = array(
            'pflege', 'ausbildung', 'kurs', 'seminar', 'aschke', 'bewerbung', 'weiterbildung',
            'nursing', 'care', 'bewerben', 'anmeldung', 'pflegefach', 'schule',
        );

        return ! $this->text_matches_patterns($text, $nursing_rescue_patterns, false);
    }

    private function build_off_topic_answer($text, $reply_language = 'Deutsch')
    {
        if ('English' !== $reply_language) {
            $responses = array(
                'Das ist nicht mein Fachgebiet — aber Pflegeausbildungen kenne ich in- und auswendig!',
                'Dafür bin ich leider nicht zuständig. Fragen rund um Aschke Seminare beantworte ich aber sehr gerne!',
                'Das liegt außerhalb meines Wissensbereichs — ich bin nur für die Aschke Welt da.',
                'Gute Frage, aber leider die falsche Adresse. Bei Ausbildungs- oder Kursfragen helfe ich dir weiter!',
            );
            if (preg_match('/wetter|regen|schnee|sonne|temperatur/u', $text)) {
                return 'Keine Ahnung wie das Wetter wird — aber für deine Pflegeausbildung scheint die Sonne das ganze Jahr!';
            }
            if (preg_match('/essen|pizza|restaurant|bestell/u', $text)) {
                return 'Essen bestellen kann ich leider nicht — aber einen guten Ausbildungsplatz finde ich für dich!';
            }
        } else {
            $responses = array(
                'That\'s outside my area — but I know everything about nursing training at Aschke Seminare!',
                'I\'m only here for questions about Aschke Seminare, sorry!',
            );
        }

        return $responses[ array_rand($responses) ];
    }

    private function get_last_assistant_message(array $history)
    {
        foreach (array_reverse($history) as $item) {
            if (($item['role'] ?? '') !== 'assistant') {
                continue;
            }

            $content = mb_strtolower(trim((string) ($item['content'] ?? '')));
            if ('' !== $content) {
                return $content;
            }
        }

        return '';
    }

    private function is_domain_relevant_info_question($text)
    {
        $text = $this->knowledge_base->normalize_for_match((string) $text);
        if ('' === $text) {
            return false;
        }

        // Allgemeine Informationsfragen zum Pflegeberuf oder zur Ausbildung,
        // die keine spezifischen Seiten benötigen, aber Cari beantworten kann.
        $info_patterns = array(
            'was macht man als',
            'was macht ein',
            'was macht eine',
            'was macht der',
            'was macht die',
            'was ist ein pfleger',
            'was ist eine pflegerin',
            'was ist pflege',
            'was ist pflegefachkraft',
            'was ist pflegefachfrau',
            'was ist pflegefachmann',
            'was ist pflegefachassistenz',
            'was ist betreuungskraft',
            'wie sieht der alltag',
            'wie sieht ein arbeitstag',
            'was sind aufgaben',
            'welche aufgaben hat',
            'was beinhaltet die ausbildung',
            'was lernt man',
            'what does a nurse do',
            'what is nursing',
            'what is a nurse',
            'what does a caregiver do',
        );

        foreach ($info_patterns as $pattern) {
            if (false !== mb_strpos($text, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function is_persona_smalltalk_request($question, array $history)
    {
        $parts = array($question);

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

        $text = mb_strtolower(implode("\n", array_reverse($parts)));
        $patterns = array(
            'wie geht',
            'wie gehts',
            'wie geht es dir',
            'how are you',
            'who are you',
            'wer bist du',
            'erzaehl von dir',
            'erzähl von dir',
            'erzaehl mal von dir',
            'erzähl mal von dir',
            'bist du mensch',
            'warst du krankenschwester',
            'warst du pflegerin',
            'frueher krankenschwester',
            'früher krankenschwester',
            'deine geschichte',
            'deine geschichte?',
            'deine story',
        );

        foreach ($patterns as $pattern) {
            if (false !== mb_strpos($text, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
