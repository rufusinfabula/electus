<?php

return [
    // General
    'app_name'       => 'Electus',
    'save'           => 'Salva',
    'cancel'         => 'Annulla',
    'delete'         => 'Elimina',
    'edit'           => 'Modifica',
    'create'         => 'Crea',
    'back'           => 'Indietro',
    'yes'            => 'Sì',
    'no'             => 'No',
    'actions'        => 'Azioni',
    'status'         => 'Stato',
    'name'           => 'Nome',
    'email'          => 'Email',
    'description'    => 'Descrizione',
    'created_at'     => 'Creato il',
    'confirm_delete' => 'Sei sicuro di voler eliminare questo elemento? L\'azione non può essere annullata.',

    // Auth
    'login'              => 'Accedi',
    'logout'             => 'Esci',
    'password'           => 'Password',
    'login_title'        => 'Accesso admin',
    'invalid_credentials'=> 'Email o password non validi.',
    'welcome_back'       => 'Bentornato, :name.',

    // Navigation
    'nav_dashboard'  => 'Dashboard',
    'nav_events'     => 'Eventi',
    'nav_users'      => 'Utenti',
    'nav_settings'   => 'Impostazioni',

    // Dashboard
    'dashboard_title'       => 'Dashboard',
    'dashboard_events_total'=> 'Eventi totali',
    'dashboard_active'      => 'Attivi',
    'dashboard_votes_today' => 'Voti oggi',

    // Events
    'events_title'       => 'Eventi',
    'event_new'          => 'Nuovo evento',
    'event_edit'         => 'Modifica evento',
    'event_saved'        => 'Evento salvato.',
    'event_deleted'      => 'Evento eliminato.',
    'event_slug'         => 'Slug',
    'event_type'         => 'Tipo',
    'event_access_mode'  => 'Modalità di accesso',
    'event_status'       => 'Stato',
    'event_results_public'   => 'Risultati pubblici',
    'event_results_timing'   => 'Pubblicazione risultati',
    'event_email_verification' => 'Verifica email',

    'access_anonymous'                 => 'Aperto — anonimo',
    'access_voluntary_registration'    => 'Aperto — registrazione volontaria',
    'access_mandatory_registration'    => 'Aperto — registrazione obbligatoria',
    'access_closed_list'               => 'Lista chiusa (token)',
    'access_registration_with_approval'=> 'Registrazione con approvazione admin',

    'results_timing_realtime'   => 'In tempo reale',
    'results_timing_manual'     => 'Rilascio manuale',
    'results_timing_after_close'=> 'Dopo la chiusura del turno',

    'event_status_draft'    => 'Bozza',
    'event_status_active'   => 'Attivo',
    'event_status_closed'   => 'Chiuso',
    'event_status_archived' => 'Archiviato',

    // Rounds
    'rounds_title'      => 'Turni',
    'round_new'         => 'Nuovo turno',
    'round_edit'        => 'Modifica turno',
    'round_saved'       => 'Turno salvato.',
    'round_number'      => 'Turno #',
    'round_label'       => 'Etichetta',
    'round_model'       => 'Modello di voto',
    'round_opens_at'    => 'Apertura',
    'round_closes_at'   => 'Chiusura',

    'model_open'         => 'Aperto (testo libero)',
    'model_single'       => 'Scelta singola',
    'model_multiple'     => 'Scelta multipla',
    'model_borda'        => 'A punti (metodo Borda)',
    'model_proportional' => 'Proporzionale',
    'model_weighted'     => 'Voto pesato',

    // Categories
    'categories_title' => 'Categorie',
    'category_new'     => 'Nuova categoria',
    'category_saved'   => 'Categoria salvata.',
    'category_deleted' => 'Categoria eliminata.',
    'sort_order'       => 'Ordine',

    // Candidates
    'candidates_title'  => 'Candidati',
    'candidate_new'     => 'Aggiungi candidato',
    'candidate_saved'   => 'Candidato salvato.',
    'candidate_deleted' => 'Candidato eliminato.',
    'candidate_name'    => 'Nome candidato',

    // Voters
    'voters_title'     => 'Elettori',
    'voters_import'    => 'Importa CSV',
    'voter_token_sent' => 'Token inviato.',
    'voter_approved'   => 'Elettore approvato.',
    'voter_rejected'   => 'Elettore rifiutato.',
    'participation'    => 'Partecipazione',
    'voted'            => 'Ha votato',
    'not_voted'        => 'Non ha votato',

    // Deduplication
    'dedup_title'      => 'Coda deduplicazione',
    'dedup_raw'        => 'Input originale',
    'dedup_normalized' => 'Normalizzato',
    'dedup_suggestion' => 'Corrispondenza suggerita',
    'dedup_score'      => 'Punteggio',
    'dedup_merge'      => 'Unisci',
    'dedup_keep'       => 'Mantieni separato',
    'dedup_exclude'    => 'Escludi',

    // Results
    'results_title'       => 'Risultati',
    'results_compute'     => 'Calcola risultati',
    'results_computed'    => 'Risultati calcolati.',
    'results_export_csv'  => 'Esporta CSV',
    'results_export_json' => 'Esporta JSON',
    'total_votes'         => 'Voti totali',
    'total_points'        => 'Punti totali',
    'rank'                => 'Posizione',

    // Users
    'users_title'         => 'Utenti',
    'user_new'            => 'Nuovo utente',
    'user_edit'           => 'Modifica utente',
    'user_saved'          => 'Utente salvato.',
    'user_deleted'        => 'Utente eliminato.',
    'user_role'           => 'Ruolo',
    'role_superadmin'     => 'Super admin',
    'role_event_manager'  => 'Gestore evento',
    'role_results_reader' => 'Lettore risultati',

    // Public vote interface
    'vote_title'        => 'Vota',
    'vote_submit'       => 'Invia voto',
    'vote_confirmed'    => 'Il tuo voto è stato registrato. Grazie.',
    'vote_already_voted'=> 'Hai già votato in questo turno.',
    'vote_closed'       => 'Questo turno non è attualmente aperto.',
    'vote_register'     => 'Registrati per votare',
    'vote_your_email'   => 'Il tuo indirizzo email',
    'vote_your_name'    => 'Il tuo nome (opzionale)',
    'vote_consent'      => 'Accetto di ricevere aggiornamenti su questo evento.',
    'vote_proceed'      => 'Procedi al voto',
    'vote_open_hint'    => 'Scrivi il nome del candidato che preferisci.',
    'vote_verify_sent'  => 'Ti abbiamo inviato un link di conferma. Clicca per sbloccare il voto.',

    // Installer
    'install_title'           => 'Electus — Installazione',
    'install_step_welcome'    => 'Benvenuto',
    'install_step_database'   => 'Database',
    'install_step_admin'      => 'Account admin',
    'install_step_done'       => 'Completato',
    'install_requirements_ok' => 'Tutti i requisiti sono soddisfatti.',
    'install_db_host'         => 'Host database',
    'install_db_port'         => 'Porta',
    'install_db_name'         => 'Nome database',
    'install_db_user'         => 'Utente database',
    'install_db_pass'         => 'Password database',
    'install_admin_name'      => 'Nome admin',
    'install_admin_email'     => 'Email admin',
    'install_admin_pass'      => 'Password admin',
    'install_success'         => 'Electus è stato installato con successo.',
    'install_go_admin'        => 'Vai al pannello admin',
];
