<?php

if (! defined('ABSPATH')) {
    exit;
}

class WPCA_Admin
{
    private $knowledge_base;

    public function __construct(WPCA_Knowledge_Base $knowledge_base)
    {
        $this->knowledge_base = $knowledge_base;
    }

    public function register()
    {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_wpca_save_faq', array($this, 'handle_save_faq'));
        add_action('admin_post_wpca_import_faqs', array($this, 'handle_import_faqs'));
        add_action('admin_post_wpca_delete_faq', array($this, 'handle_delete_faq'));
        add_action('admin_post_wpca_delete_selected_faqs', array($this, 'handle_delete_selected_faqs'));
        add_action('admin_post_wpca_delete_imported_faqs', array($this, 'handle_delete_imported_faqs'));
        add_action('admin_post_wpca_sync_content', array($this, 'handle_sync_content'));
        add_action('admin_post_wpca_promote_log_to_faq', array($this, 'handle_promote_log_to_faq'));
        add_action('admin_post_wpca_apply_log_keywords', array($this, 'handle_apply_log_keywords'));
        add_action('admin_post_wpca_ignore_log', array($this, 'handle_ignore_log'));
    }

    public function register_settings()
    {
        $this->knowledge_base->register_settings();
    }

    public function menu()
    {
        add_menu_page(
            __('Aschke Assist', 'company-faq-chatbot'),
            __('Aschke Assist', 'company-faq-chatbot'),
            'manage_options',
            'wpca-chatbot',
            array($this, 'render_settings_page'),
            'dashicons-format-chat'
        );
    }

    public function render_settings_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $settings = $this->knowledge_base->get_settings();
        $faqs = $this->knowledge_base->get_faqs();
        $logs = array();
        $logs_error = '';
        try {
            $logs = $this->knowledge_base->get_recent_logs(40);
        } catch (Throwable $exception) {
            $logs_error = $exception->getMessage();
        }
        $last_sync = get_transient(WPCA_Knowledge_Base::TRANSIENT_LAST_SYNC);
        $test_query = sanitize_text_field(wp_unslash($_GET['wpca_test_query'] ?? ''));
        $editing_faq_id = absint($_GET['edit_faq'] ?? 0);
        $editing_faq = $editing_faq_id ? $this->knowledge_base->get_faq($editing_faq_id) : null;
        $relevance_debug = '' !== $test_query ? $this->knowledge_base->debug_relevance($test_query, 8) : null;
        ?>
        <div class="wrap wpca-admin-page">
            <h1>Aschke Assist</h1>
            <p>Automatischer Website-Chatbot mit OpenAI, Website-Wissensbasis, manuellen FAQs und intelligenter Themenbegrenzung.</p>
            <?php $this->render_notices(); ?>

            <div class="wpca-admin-grid">
                <section class="wpca-card">
                    <h2>Einstellungen</h2>
                    <form method="post" action="options.php">
                        <?php settings_fields('wpca_settings_group'); ?>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">Chatbot aktiv</th>
                                <td><label><input type="checkbox" name="wpca_settings[enabled]" value="1" <?php checked(! empty($settings['enabled'])); ?>> Automatisch unten rechts anzeigen</label></td>
                            </tr>
                            <tr>
                                <th scope="row">OpenAI API-Key</th>
                                <td><input type="password" class="regular-text" name="wpca_settings[openai_api_key]" value="<?php echo esc_attr($settings['openai_api_key']); ?>" autocomplete="off"></td>
                            </tr>
                            <tr>
                                <th scope="row">Chat-Modell</th>
                                <td><input type="text" class="regular-text" name="wpca_settings[chat_model]" value="<?php echo esc_attr($settings['chat_model']); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row">Embedding-Modell</th>
                                <td><input type="text" class="regular-text" name="wpca_settings[embedding_model]" value="<?php echo esc_attr($settings['embedding_model']); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row">Antwortsprache</th>
                                <td>
                                    <select name="wpca_settings[response_language]">
                                        <option value="auto" <?php selected(($settings['response_language'] ?? 'auto'), 'auto'); ?>>Automatisch erkennen</option>
                                        <option value="de" <?php selected(($settings['response_language'] ?? 'auto'), 'de'); ?>>Deutsch</option>
                                        <option value="en" <?php selected(($settings['response_language'] ?? 'auto'), 'en'); ?>>English</option>
                                    </select>
                                    <p class="description">Legt fest, ob Antworten automatisch, auf Deutsch oder auf Englisch erzeugt werden sollen.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Haupt-Website</th>
                                <td>
                                    <input type="url" class="regular-text" name="wpca_settings[source_website]" value="<?php echo esc_attr($settings['source_website']); ?>">
                                    <p class="description">Start-URL der Website, die gecrawlt und indexiert werden soll.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Unterseiten als Quellen</th>
                                <td>
                                    <textarea class="large-text code" rows="7" name="wpca_settings[source_urls]" placeholder="https://deine-seite.de/&#10;https://deine-seite.de/leistungen&#10;https://deine-seite.de/faq"><?php echo esc_textarea($settings['source_urls']); ?></textarea>
                                    <p class="description">Eine URL pro Zeile. Diese Seiten werden direkt als Wissensquellen verwendet, damit moeglichst viel relevantes Wissen indexiert wird.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Widget-Titel</th>
                                <td>
                                    <input type="text" class="regular-text" name="wpca_settings[widget_title]" value="<?php echo esc_attr($settings['widget_title']); ?>">
                                    <p class="description">Das ist der Hauptname im Popup und im geoeffneten Chat, zum Beispiel <code>Asky</code> oder <code>Aschke Assist</code>.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Popup-Teaser / CTA</th>
                                <td>
                                    <div class="wpca-inline-fields">
                                        <label>
                                            <span class="wpca-inline-fields__label">Kleine Zeile</span>
                                            <input type="text" class="regular-text" name="wpca_settings[launcher_eyebrow]" value="<?php echo esc_attr($settings['launcher_eyebrow'] ?? ''); ?>">
                                        </label>
                                        <label>
                                            <span class="wpca-inline-fields__label">Haupttext</span>
                                            <input type="text" class="large-text" name="wpca_settings[launcher_label]" value="<?php echo esc_attr($settings['launcher_label'] ?? ''); ?>">
                                        </label>
                                        <label>
                                            <span class="wpca-inline-fields__label">Unterzeile</span>
                                            <input type="text" class="large-text" name="wpca_settings[launcher_description]" value="<?php echo esc_attr($settings['launcher_description'] ?? ''); ?>">
                                        </label>
                                        <label>
                                            <span class="wpca-inline-fields__label">CTA-Button</span>
                                            <input type="text" class="regular-text" name="wpca_settings[launcher_cta]" value="<?php echo esc_attr($settings['launcher_cta'] ?? ''); ?>">
                                        </label>
                                    </div>
                                    <p class="description">Diese Texte steuern das kleine Popup unten rechts. Du kannst sie jederzeit im Backend aendern, ohne den Code anzufassen.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Avatar-Bild</th>
                                <td>
                                    <div class="wpca-avatar-setting">
                                        <img class="wpca-avatar-preview<?php echo empty($settings['avatar_url']) ? ' is-empty' : ''; ?>" src="<?php echo esc_url($settings['avatar_url']); ?>" alt=""<?php echo empty($settings['avatar_url']) ? ' hidden' : ''; ?>>
                                        <input type="url" class="regular-text wpca-avatar-url" name="wpca_settings[avatar_url]" value="<?php echo esc_attr($settings['avatar_url']); ?>" placeholder="https://...">
                                        <button type="button" class="button wpca-avatar-upload">Bild aus Mediathek waehlen</button>
                                        <button type="button" class="button-link-delete wpca-avatar-remove"<?php echo empty($settings['avatar_url']) ? ' hidden' : ''; ?>>Avatar entfernen</button>
                                    </div>
                                    <p class="description">Du kannst ein eigenes Avatar-Bild aus der WordPress-Mediathek auswaehlen oder eine Bild-URL eintragen.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Willkommensnachricht</th>
                                <td><textarea class="large-text" rows="4" name="wpca_settings[welcome_message]"><?php echo esc_textarea($settings['welcome_message']); ?></textarea></td>
                            </tr>
                            <tr>
                                <th scope="row">Texte im Chatfenster</th>
                                <td>
                                    <div class="wpca-inline-fields">
                                        <label>
                                            <span class="wpca-inline-fields__label">Kleine Ueberschrift</span>
                                            <input type="text" class="regular-text" name="wpca_settings[panel_eyebrow]" value="<?php echo esc_attr($settings['panel_eyebrow'] ?? ''); ?>">
                                        </label>
                                        <label>
                                            <span class="wpca-inline-fields__label">Intro-Zeile</span>
                                            <input type="text" class="large-text" name="wpca_settings[hero_intro]" value="<?php echo esc_attr($settings['hero_intro'] ?? ''); ?>">
                                        </label>
                                        <label>
                                            <span class="wpca-inline-fields__label">Badge rechts</span>
                                            <input type="text" class="regular-text" name="wpca_settings[brand_badge]" value="<?php echo esc_attr($settings['brand_badge'] ?? ''); ?>">
                                        </label>
                                        <label>
                                            <span class="wpca-inline-fields__label">Hinweis unten</span>
                                            <input type="text" class="large-text" name="wpca_settings[footnote]" value="<?php echo esc_attr($settings['footnote'] ?? ''); ?>">
                                        </label>
                                    </div>
                                    <p class="description">Hier kannst du auch Texte wie <code>Freundlicher Assistent</code> oder den kleinen Hinweis unten im Chatfenster frei anpassen.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Verhaltensprompt</th>
                                <td>
                                    <textarea class="large-text code" rows="6" name="wpca_settings[behavior_prompt]" placeholder="Zum Beispiel: Antworte knapp. Keine Begruessung in jeder Nachricht. Keine Hinweise auf interne Prozesse."><?php echo esc_textarea($settings['behavior_prompt'] ?? ''); ?></textarea>
                                    <p class="description">Hier kannst du das Antwortverhalten des Bots frei ergaenzen, ohne das Plugin erneut anpassen zu muessen.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Akzentfarbe</th>
                                <td><input type="text" class="regular-text" name="wpca_settings[accent_color]" value="<?php echo esc_attr($settings['accent_color']); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row">Textfarbe</th>
                                <td><input type="text" class="regular-text" name="wpca_settings[text_color]" value="<?php echo esc_attr($settings['text_color']); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row">Pflege-Animationen</th>
                                <td>
                                    <label><input type="checkbox" name="wpca_settings[medical_animations]" value="1" <?php checked(! empty($settings['medical_animations'])); ?>> Schwebende Pflege- und Medizin-Motive im Popup anzeigen</label>
                                    <p class="description">Blendet dekorative Animationen wie Stethoskop, Spritze und Pflegekreuz rund um den Teaser und im Chatfenster ein.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Wissensquellen je Antwort</th>
                                <td><input type="number" min="3" max="10" name="wpca_settings[max_sources]" value="<?php echo esc_attr($settings['max_sources']); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row">Lernansicht</th>
                                <td>
                                    <label><input type="checkbox" name="wpca_settings[chat_logging]" value="1" <?php checked(! empty($settings['chat_logging'])); ?>> Neue Chats fuer die Lernansicht speichern</label>
                                    <p class="description">Speichert bereinigte Fragen und Antworten im Backend, damit neue Eintraege in der Lernansicht erscheinen und du daraus FAQs ableiten kannst.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Themenbegrenzung</th>
                                <td><label><input type="checkbox" name="wpca_settings[theme_guardrail]" value="1" <?php checked(! empty($settings['theme_guardrail'])); ?>> Nur unternehmensbezogene Fragen beantworten</label></td>
                            </tr>
                        </table>
                        <?php submit_button('Einstellungen speichern'); ?>
                    </form>
                </section>

                <section class="wpca-card">
                    <h2>Website-Wissen synchronisieren</h2>
                    <p>Indexiert die hinterlegte Haupt-Website und die wichtigsten passenden Unterseiten. Der Sync ist bewusst begrenzt, damit er nicht durch zu viele Links oder FAQ-Embeddings minutenlang blockiert.</p>
                    <?php if (! empty($last_sync)) : ?>
                        <p><strong>Letzte Synchronisierung:</strong> <?php echo esc_html($last_sync['synced_at']); ?> | <?php echo esc_html($last_sync['indexed_posts']); ?> Inhalte | <?php echo esc_html($last_sync['created_chunks']); ?> Wissensbausteine</p>
                        <?php if (! empty($last_sync['errors'])) : ?>
                            <p><strong>Hinweise:</strong><br><?php echo esc_html(implode(' | ', $last_sync['errors'])); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="wpca_sync_content">
                        <?php wp_nonce_field('wpca_sync_content'); ?>
                        <?php submit_button('Jetzt Website neu indexieren', 'secondary'); ?>
                    </form>
                </section>
            </div>

            <div class="wpca-admin-stack">
                <section class="wpca-card wpca-card--full">
                    <h2><?php echo $editing_faq ? 'FAQ bearbeiten' : 'Eigene FAQ-Antworten anlegen'; ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="wpca_save_faq">
                        <input type="hidden" name="faq_id" value="<?php echo esc_attr($editing_faq ? $editing_faq->id : 0); ?>">
                        <?php wp_nonce_field('wpca_save_faq'); ?>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">Frage</th>
                                <td><input type="text" class="large-text" name="question" value="<?php echo esc_attr($editing_faq->question ?? ''); ?>" required></td>
                            </tr>
                            <tr>
                                <th scope="row">Antwort</th>
                                <td><textarea class="large-text" rows="5" name="answer" required><?php echo esc_textarea($editing_faq->answer ?? ''); ?></textarea></td>
                            </tr>
                            <tr>
                                <th scope="row">Keywords</th>
                                <td>
                                    <input type="text" class="large-text" name="keywords" value="<?php echo esc_attr($editing_faq->keywords ?? ''); ?>" placeholder="z. B. preis, kosten, gebuehr, beratung">
                                    <p class="description">Optional. Eigene Stichwoerter helfen dabei, aehnliche Fragen gezielter zu erkennen.</p>
                                </td>
                            </tr>
                        </table>
                        <div class="wpca-form-actions">
                            <?php submit_button($editing_faq ? 'FAQ aktualisieren' : 'FAQ speichern', 'primary', 'submit', false); ?>
                            <?php if ($editing_faq) : ?>
                                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wpca-chatbot')); ?>">Bearbeitung abbrechen</a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <hr>
                    <h3>FAQ per Excel oder CSV importieren</h3>
                    <p>Erwartete Spalten: <code>question</code> und <code>answer</code> oder <code>Frage</code> und <code>Antwort</code>. Optional zusaetzlich: <code>keywords</code>, <code>category</code>, <code>focus</code>, <code>source_url</code>, <code>id</code> und <code>language</code>. Varianten mit gemeinsamer FAQ-ID werden beim Import automatisch zusammengefuehrt.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="wpca_import_faqs">
                        <?php wp_nonce_field('wpca_import_faqs'); ?>
                        <input type="file" name="faq_file" accept=".csv,.xlsx" required>
                        <?php submit_button('FAQ-Datei importieren', 'secondary'); ?>
                    </form>

                    <div class="wpca-faq-tools">
                        <form id="wpca-delete-selected-faqs" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="wpca_delete_selected_faqs">
                            <?php wp_nonce_field('wpca_delete_selected_faqs'); ?>
                            <?php submit_button('Ausgewaehlte FAQs loeschen', 'delete', 'submit', false); ?>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm('Alle importierten FAQs wirklich loeschen?');">
                            <input type="hidden" name="action" value="wpca_delete_imported_faqs">
                            <?php wp_nonce_field('wpca_delete_imported_faqs'); ?>
                            <?php submit_button('Alle importierten FAQs loeschen', 'delete', 'submit', false); ?>
                        </form>
                    </div>

                    <?php if (! empty($faqs)) : ?>
                        <div class="wpca-faq-search">
                            <label class="screen-reader-text" for="wpca-faq-search">FAQ durchsuchen</label>
                            <input
                                type="search"
                                id="wpca-faq-search"
                                class="regular-text"
                                placeholder="FAQ nach Frage, Antwort oder Keywords durchsuchen"
                                autocomplete="off"
                            >
                            <p class="description">Filtert die FAQ-Liste live nach Frage, Antwort und Keywords.</p>
                        </div>
                        <div class="wpca-table-wrap">
                            <table class="widefat striped wpca-table wpca-table--faqs">
                                <thead>
                                <tr>
                                    <th><input type="checkbox" class="wpca-select-all-faqs" aria-label="Alle FAQs auswaehlen"></th>
                                    <th>Frage</th>
                                    <th>Antwort</th>
                                    <th>Keywords</th>
                                    <th>Typ</th>
                                    <th>Aktion</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($faqs as $faq) : ?>
                                    <tr data-wpca-faq-search="<?php echo esc_attr($faq->question . ' ' . wp_strip_all_tags($faq->answer) . ' ' . (string) $faq->keywords . ' ' . (($faq->source ?? 'manual') === 'import' ? 'Import' : 'Manuell')); ?>">
                                        <td>
                                            <input type="checkbox" name="faq_ids[]" value="<?php echo esc_attr($faq->id); ?>" form="wpca-delete-selected-faqs">
                                        </td>
                                        <td><div class="wpca-cell-text wpca-cell-text--question"><?php echo esc_html($faq->question); ?></div></td>
                                        <td><div class="wpca-cell-text wpca-cell-text--answer"><?php echo esc_html(wp_strip_all_tags($faq->answer)); ?></div></td>
                                        <td><div class="wpca-cell-text"><?php echo esc_html((string) $faq->keywords); ?></div></td>
                                        <td><?php echo esc_html(($faq->source ?? 'manual') === 'import' ? 'Import' : 'Manuell'); ?></td>
                                        <td>
                                            <div class="wpca-row-actions">
                                                <a class="button button-secondary button-small" href="<?php echo esc_url(add_query_arg(array('page' => 'wpca-chatbot', 'edit_faq' => (int) $faq->id), admin_url('admin.php'))); ?>">Bearbeiten</a>
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                    <input type="hidden" name="action" value="wpca_delete_faq">
                                                    <input type="hidden" name="faq_id" value="<?php echo esc_attr($faq->id); ?>">
                                                    <?php wp_nonce_field('wpca_delete_faq_' . $faq->id); ?>
                                                    <?php submit_button('Loeschen', 'delete small', 'submit', false); ?>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="description wpca-faq-search__empty" hidden>Keine FAQs passen zu dieser Suche.</p>
                    <?php endif; ?>
                </section>

                <section class="wpca-card wpca-card--full">
                    <h2>Lernansicht</h2>
                    <p>Die letzten Chatfragen werden gespeichert. So kannst du neue manuelle FAQs daraus ableiten und den Bot gezielt verbessern.</p>
                    <?php if (empty($settings['chat_logging'])) : ?>
                        <p class="description"><strong>Hinweis:</strong> Die Lernansicht ist aktuell deaktiviert. Aktiviere oben die Option <em>Neue Chats fuer die Lernansicht speichern</em>, damit neue Eintraege hier erscheinen.</p>
                    <?php endif; ?>
                    <?php if ('' !== $logs_error) : ?>
                        <p class="description"><strong>Hinweis:</strong> Die Lernansicht konnte nicht vollstaendig geladen werden: <?php echo esc_html($logs_error); ?></p>
                    <?php endif; ?>
                    <div class="wpca-table-wrap">
                        <table class="widefat striped wpca-table wpca-table--logs">
                            <thead>
                            <tr>
                                <th>Frage</th>
                                <th>Antwort</th>
                                <th>Hinweise</th>
                                <th>Zeit</th>
                                <th>Aktion</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (! empty($logs)) : ?>
                                <?php foreach ($logs as $log) : ?>
                                    <tr>
                                        <td>
                                            <div class="wpca-cell-text wpca-cell-text--question"><?php echo esc_html($log->user_question); ?></div>
                                            <?php if (! empty($log->is_frequent)) : ?>
                                                <p><span class="wpca-score-pill wpca-score-pill--medium">Haeufig gefragt: <?php echo esc_html((int) $log->frequency_count); ?>x</span></p>
                                            <?php endif; ?>
                                        </td>
                                        <td><div class="wpca-cell-text wpca-cell-text--answer"><?php echo esc_html(wp_strip_all_tags($log->assistant_answer)); ?></div></td>
                                        <td>
                                            <div><strong>Konfidenz:</strong> <?php echo esc_html(number_format_i18n((float) $log->confidence_score, 2)); ?></div>
                                            <?php if (! empty($log->suggested_keywords)) : ?>
                                                <div><strong>Vorschlag:</strong> <?php echo esc_html(implode(', ', (array) $log->suggested_keywords)); ?></div>
                                            <?php endif; ?>
                                            <?php if (($log->status ?? '') === 'promoted' && ! empty($log->promoted_faq_id)) : ?>
                                                <div><strong>Status:</strong> Als FAQ uebernommen</div>
                                            <?php elseif (($log->status ?? '') === 'keywords') : ?>
                                                <div><strong>Status:</strong> Keywords uebernommen</div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html(mysql2date('d.m.Y H:i', $log->created_at)); ?></td>
                                        <td>
                                            <div class="wpca-row-actions">
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                    <input type="hidden" name="action" value="wpca_promote_log_to_faq">
                                                    <input type="hidden" name="log_id" value="<?php echo esc_attr($log->id); ?>">
                                                    <?php wp_nonce_field('wpca_promote_log_' . $log->id); ?>
                                                    <?php
                                                    $is_promoted = ($log->status ?? '') === 'promoted';
                                                    $btn_attrs = $is_promoted ? array('disabled' => 'disabled') : array();
                                                    submit_button('Als FAQ uebernehmen', 'secondary small', 'submit', false, $btn_attrs);
                                                    ?>
                                                </form>
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                    <input type="hidden" name="action" value="wpca_apply_log_keywords">
                                                    <input type="hidden" name="log_id" value="<?php echo esc_attr($log->id); ?>">
                                                    <?php wp_nonce_field('wpca_apply_log_keywords_' . $log->id); ?>
                                                    <?php submit_button('Synonyme uebernehmen', 'secondary small', 'submit', false); ?>
                                                </form>
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                    <input type="hidden" name="action" value="wpca_ignore_log">
                                                    <input type="hidden" name="log_id" value="<?php echo esc_attr($log->id); ?>">
                                                    <?php wp_nonce_field('wpca_ignore_log_' . $log->id); ?>
                                                    <?php submit_button('Ignorieren', 'delete small', 'submit', false); ?>
                                                </form>
                                                <?php if (($log->status ?? '') === 'promoted' && ! empty($log->promoted_faq_id)) : ?>
                                                    <a class="button button-secondary button-small" href="<?php echo esc_url(add_query_arg(array('page' => 'wpca-chatbot', 'edit_faq' => (int) $log->promoted_faq_id), admin_url('admin.php'))); ?>">FAQ ansehen</a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr><td colspan="5">Noch keine Chatprotokolle vorhanden.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="wpca-card wpca-card--full">
                    <h2>Admin-Testmodus</h2>
                    <p>Hier kannst du eine Testfrage eingeben und sofort sehen, welche FAQs oder Seiten der Bot als besonders relevant bewertet.</p>
                    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="wpca-test-form">
                        <input type="hidden" name="page" value="wpca-chatbot">
                        <label class="screen-reader-text" for="wpca-test-query">Testfrage</label>
                        <textarea id="wpca-test-query" class="large-text" rows="3" name="wpca_test_query" placeholder="Zum Beispiel: Was kostet eine Beratung?"><?php echo esc_textarea($test_query); ?></textarea>
                        <div class="wpca-test-form__actions">
                            <?php submit_button('Relevanz testen', 'secondary', 'submit', false); ?>
                            <?php if ('' !== $test_query) : ?>
                                <a class="button button-link" href="<?php echo esc_url(admin_url('admin.php?page=wpca-chatbot')); ?>">Zuruecksetzen</a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <?php if (! empty($relevance_debug)) : ?>
                        <?php $summary = $relevance_debug['confidence']; ?>
                        <div class="wpca-debug-summary">
                            <span class="wpca-score-pill wpca-score-pill--<?php echo esc_attr($summary['level']); ?>">Konfidenz: <?php echo esc_html(strtoupper($summary['level'])); ?></span>
                            <span class="wpca-score-pill">Top-Score: <?php echo esc_html(number_format_i18n((float) $summary['best_score'], 2)); ?></span>
                            <span class="wpca-score-pill">Abstand: <?php echo esc_html(number_format_i18n((float) $summary['gap'], 2)); ?></span>
                            <span class="wpca-score-pill">Typ: <?php echo esc_html($summary['top_type'] ?: '-'); ?></span>
                        </div>
                        <p class="description"><?php echo esc_html($summary['reason']); ?></p>

                        <div class="wpca-table-wrap">
                            <table class="widefat striped wpca-table">
                                <thead>
                                <tr>
                                    <th>Typ</th>
                                    <th>Titel</th>
                                    <th>Score</th>
                                    <th>Signale</th>
                                    <th>Link</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (! empty($relevance_debug['results'])) : ?>
                                    <?php foreach ($relevance_debug['results'] as $item) : ?>
                                        <tr>
                                            <td><?php echo esc_html($item['type']); ?></td>
                                            <td><div class="wpca-cell-text"><?php echo esc_html($item['title']); ?></div></td>
                                            <td><?php echo esc_html(number_format_i18n((float) ($item['score'] ?? 0), 2)); ?></td>
                                            <td class="wpca-debug-signals">
                                                <?php foreach (($item['debug'] ?? array()) as $label => $value) : ?>
                                                    <?php if ((float) $value <= 0) { continue; } ?>
                                                    <div><strong><?php echo esc_html($label); ?>:</strong> <?php echo esc_html(number_format_i18n((float) $value, 2)); ?></div>
                                                <?php endforeach; ?>
                                            </td>
                                            <td class="wpca-debug-url">
                                                <?php if (! empty($item['url'])) : ?>
                                                    <a href="<?php echo esc_url($item['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($item['url']); ?></a>
                                                <?php else : ?>
                                                    <span>-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <tr><td colspan="5">Noch keine Treffer fuer diese Testfrage.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
        <?php
    }

    private function render_notices()
    {
        if (! empty($_GET['wpca_notice'])) {
            $notice = sanitize_text_field(wp_unslash($_GET['wpca_notice']));
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
        }

        if (! empty($_GET['wpca_error'])) {
            $error = sanitize_text_field(wp_unslash($_GET['wpca_error']));
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error) . '</p></div>';
        }
    }

    public function handle_save_faq()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Nicht erlaubt');
        }

        check_admin_referer('wpca_save_faq');
        $this->knowledge_base->save_faq(
            wp_unslash($_POST['question'] ?? ''),
            wp_unslash($_POST['answer'] ?? ''),
            absint($_POST['faq_id'] ?? 0),
            wp_unslash($_POST['keywords'] ?? '')
        );

        wp_safe_redirect(
            add_query_arg(
                'wpca_notice',
                absint($_POST['faq_id'] ?? 0) > 0 ? 'FAQ aktualisiert.' : 'FAQ gespeichert.',
                admin_url('admin.php?page=wpca-chatbot')
            )
        );
        exit;
    }

    public function handle_delete_faq()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Nicht erlaubt');
        }

        $faq_id = absint($_POST['faq_id'] ?? 0);
        check_admin_referer('wpca_delete_faq_' . $faq_id);
        $this->knowledge_base->delete_faq($faq_id);

        wp_safe_redirect(admin_url('admin.php?page=wpca-chatbot'));
        exit;
    }

    public function handle_delete_selected_faqs()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Nicht erlaubt');
        }

        check_admin_referer('wpca_delete_selected_faqs');
        $deleted = $this->knowledge_base->delete_faqs(wp_unslash($_POST['faq_ids'] ?? array()));

        wp_safe_redirect(
            add_query_arg(
                'wpca_notice',
                sprintf('%d FAQs geloescht.', (int) $deleted),
                admin_url('admin.php?page=wpca-chatbot')
            )
        );
        exit;
    }

    public function handle_delete_imported_faqs()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Nicht erlaubt');
        }

        check_admin_referer('wpca_delete_imported_faqs');
        $deleted = $this->knowledge_base->delete_imported_faqs();

        wp_safe_redirect(
            add_query_arg(
                'wpca_notice',
                sprintf('%d importierte FAQs geloescht.', (int) $deleted),
                admin_url('admin.php?page=wpca-chatbot')
            )
        );
        exit;
    }

    public function handle_import_faqs()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Nicht erlaubt');
        }

        check_admin_referer('wpca_import_faqs');
        $result = $this->knowledge_base->import_faq_file($_FILES['faq_file'] ?? array());

        if (is_wp_error($result)) {
            wp_safe_redirect(
                add_query_arg(
                    'wpca_error',
                    $result->get_error_message(),
                    admin_url('admin.php?page=wpca-chatbot')
                )
            );
            exit;
        }

        $notice = sprintf('FAQ-Import abgeschlossen: %d importiert, %d uebersprungen.', (int) $result['imported'], (int) $result['skipped']);
        if (! empty($result['collapsed'])) {
            $notice .= sprintf(' %d Dubletten bzw. Varianten wurden zusammengefuehrt.', (int) $result['collapsed']);
        }

        wp_safe_redirect(
            add_query_arg(
                'wpca_notice',
                $notice,
                admin_url('admin.php?page=wpca-chatbot')
            )
        );
        exit;
    }

    public function handle_sync_content()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Nicht erlaubt');
        }

        check_admin_referer('wpca_sync_content');
        $result = $this->knowledge_base->sync_site_content();

        wp_safe_redirect(
            add_query_arg(
                'wpca_notice',
                sprintf('Synchronisierung abgeschlossen: %d Inhalte, %d Wissensbausteine.', (int) $result['indexed_posts'], (int) $result['created_chunks']),
                admin_url('admin.php?page=wpca-chatbot')
            )
        );
        exit;
    }

    public function handle_promote_log_to_faq()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Nicht erlaubt');
        }

        $log_id = absint($_POST['log_id'] ?? 0);
        check_admin_referer('wpca_promote_log_' . $log_id);
        $result = $this->knowledge_base->promote_log_to_faq($log_id);

        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg('wpca_error', $result->get_error_message(), admin_url('admin.php?page=wpca-chatbot')));
            exit;
        }

        wp_safe_redirect(add_query_arg('wpca_notice', 'Log-Eintrag als FAQ uebernommen.', admin_url('admin.php?page=wpca-chatbot')));
        exit;
    }

    public function handle_apply_log_keywords()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Nicht erlaubt');
        }

        $log_id = absint($_POST['log_id'] ?? 0);
        check_admin_referer('wpca_apply_log_keywords_' . $log_id);
        $result = $this->knowledge_base->apply_log_keywords_to_best_faq($log_id);

        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg('wpca_error', $result->get_error_message(), admin_url('admin.php?page=wpca-chatbot')));
            exit;
        }

        wp_safe_redirect(add_query_arg('wpca_notice', 'Keywords aus dem Log wurden in die passende FAQ uebernommen.', admin_url('admin.php?page=wpca-chatbot')));
        exit;
    }

    public function handle_ignore_log()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Nicht erlaubt');
        }

        $log_id = absint($_POST['log_id'] ?? 0);
        check_admin_referer('wpca_ignore_log_' . $log_id);
        $this->knowledge_base->ignore_log($log_id);

        wp_safe_redirect(add_query_arg('wpca_notice', 'Log-Eintrag ignoriert.', admin_url('admin.php?page=wpca-chatbot')));
        exit;
    }
}
