# Änderungsprotokoll

## 0.1.0 – 18.10.2026

Grundgerüst für den Machbarkeitstest, Etappe 1.

- Plugin-Bootstrap mit Prüfung auf PHP 8.1 und vorhandene Abhängigkeiten
- 20 Plugin-Tabellen nach Spezifikation v0.3, Migration über `dbDelta()` und `lsec_db_version`
- Ablaufsteuerung: Abläufe und Schritte mit dauerhaftem Status, Idempotenz-Schlüssel, Sperre mit Ablaufzeit, Wiederholungen (1, 5, 30 Minuten), Watchdog für abgebrochene Abläufe
- Action Scheduler als Auslöser, Heartbeat alle 5 Minuten
- WP-CLI: `wp lsec status`, `wp lsec cron`, `wp lsec migrate`, `wp lsec watchdog`, `wp lsec selftest …`
- Statusseite im Backend (Menü „Local SEO“), Hinweis bei fehlendem Cronjob
- Capability `lsec_manage`, verschlüsselte Ablage von API-Schlüsseln, Schwärzung von Geheimnissen in Protokollen
- Prüfskript `bin/machbarkeitstest.sh` für die Prüfpunkte M2 bis M5
