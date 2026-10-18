# Glossar

| Begriff | Bedeutung im Projekt | Im Code |
|---|---|---|
| Entität | Ein eindeutig identifizierbares Ding: Unternehmen, Ort, Leistung, Produkt, Person, Fachbegriff. Hat eine interne ID, unabhängig von Wikidata. | `lsec_entities`, `Lsec\Entity` |
| Beziehung | Gerichtete Verbindung zwischen zwei Entitäten, z. B. „Unternehmen bietet Leistung an“. | `lsec_relations` |
| Aussage | Eine Tatsachenbehauptung über eine Entität oder Beziehung, z. B. Öffnungszeiten. Fakten sind keine Entitäten. | `lsec_claims` |
| Beleg | Verweis auf eine Quelle, die eine Entität, Beziehung oder Aussage stützt, mit Fundstelle und Qualität. | `lsec_evidence` |
| Fundstelle | Wo eine Entität auf der Website oder extern vorkommt (Titel, Überschrift, Text, Schema). | `lsec_mentions` |
| Prüfstatus | `candidate` (Kandidat), `probable` (wahrscheinlich), `confirmed` (bestätigt), `unresolved` (ungeklärt), `rejected` (abgelehnt). Neue Entitäten sind immer Kandidaten. | Spalte `status` |
| Themenvorschlag | Ein recherchiertes Thema mit Suchintention, Format, Entitäten, Keywords, Priorität und Begründung. | `lsec_topics` |
| Briefing | Gliederung und Vorgaben für einen Beitrag, erzeugt aus einem freigegebenen Thema. | `lsec_briefs` |
| Ablauf | Ein Auftrag mit mehreren Schritten, z. B. „Beitrag zu Thema 42 erstellen“. Hat einen dauerhaften Status und einen Idempotenz-Schlüssel. | `lsec_runs`, `Lsec\Workflow` |
| Schritt | Ein einzelner Arbeitsschritt eines Ablaufs. Jeder Schritt ist eine eigene Aktion im Action Scheduler. | `lsec_run_steps` |
| Idempotenz-Schlüssel | Fingerabdruck eines Ablaufs. Ein zweiter Start mit gleichem Schlüssel findet den vorhandenen Ablauf, statt einen neuen anzulegen. | `idempotency_key` |
| Sperre | Verhindert, dass zwei Prozesse denselben Ablauf gleichzeitig bearbeiten. Läuft nach einer festen Zeit ab. | `locked_until`, `lock_token` |
| Style Mimic | Das globale Stilprofil für alle erzeugten Beiträge, aus Beispieltexten, Beiträgen und Regeln abgeleitet. | `lsec_style_profiles` |
| Keyword-Quelle | Anbieter von Keyword-Daten hinter einer gemeinsamen Schnittstelle: Search Console, CSV-/Excel-Import, Textfocus, KI-Websuche. | `KeywordSource` |
| Credit-Budget | Tagesgrenze für bezahlte Abfragen bei externen Anbietern wie Textfocus. | `lsec_provider_calls` |
| Machbarkeitstest | Schmaler, lauffähiger Durchstich durch alle Schichten mit den Prüfpunkten M1 bis M18. | `PROJEKTPLAN.md` |
