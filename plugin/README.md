# Company FAQ Chatbot

WordPress-Plugin fuer einen intelligenten, unternehmensbezogenen FAQ-Chatbot mit:

- automatischer Einbindung unten rechts ohne Shortcode
- OpenAI API fuer Antwortgenerierung
- Website-Indexierung ueber oeffentliche WordPress-Inhalte
- manuellen FAQ-Eintraegen als zusaetzliches Wissen
- thematischer Begrenzung gegen Halluzinationen
- freundlicher UX in `#F39ca5` und Weiss

## Installation

1. Ordner `company-faq-chatbot` nach `wp-content/plugins/` kopieren.
2. Plugin in WordPress aktivieren.
3. Unter `FAQ Chatbot` den OpenAI API-Key eintragen.
4. `Jetzt Website neu indexieren` ausfuehren.
5. Eigene FAQ-Fragen und Antworten ergaenzen.

## Hinweise

- Der Bot antwortet nur aus indexierten Website-Inhalten und manuellen FAQ-Eintraegen.
- Fuer bessere Treffergenauigkeit werden Embeddings ueber OpenAI erzeugt.
- Taeglich laeuft automatisch ein Re-Index, zusaetzlich kann manuell synchronisiert werden.
