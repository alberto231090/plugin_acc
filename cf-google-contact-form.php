<?php
/*
Plugin Name: CF Google Contact Form
Description: Contact form con Drive, Sheets, Calendar, email admin e candidato.
Version: 2.0
Author: Alberto (Formulapaddock)
*/

add_shortcode('cf_plugin_form', 'cf_render_form');

function cf_render_form() {
    ob_start(); ?>
    <form id="cf-form" method="post" enctype="multipart/form-data">
        <label for="cf_name">Nome:</label><br>
        <input type="text" name="cf_name" required><br><br>

        <label for="cf_email">Email:</label><br>
        <input type="email" name="cf_email" required><br><br>

        <label for="cf_file">CV (PDF/DOC):</label><br>
        <input type="file" name="cf_file" accept=".pdf,.doc,.docx" required><br><br>

        <div class="g-recaptcha" data-sitekey="6Lf7vWgrAAAAAN9soV90Qwi9F_8V_Yd8LIwDUUFW"></div><br>

        <input type="submit" name="cf_submit" value="Invia">
    </form>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php return ob_get_clean();
}

add_action('init', 'cf_process_form');
function cf_process_form() {
    if (isset($_POST['cf_submit']) && !empty($_FILES['cf_file'])) {
        $nome = sanitize_text_field($_POST['cf_name']);
        $email = sanitize_email($_POST['cf_email']);

        $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
            'body' => [
                'secret' => '6Lf7vWgrAAAAACjwjkFWtJ8ewqZOKAi3EJg-5QZq',
                'response' => $_POST['g-recaptcha-response']
            ]
        ]);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!$body['success']) {
            wp_die('reCAPTCHA non valido.');
        }

        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/cf-plugin/';
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0755, true);
        }

        $filename = basename($_FILES['cf_file']['name']);
        $filepath = $target_dir . $filename;

        if (move_uploaded_file($_FILES['cf_file']['tmp_name'], $filepath)) {
            $link_cv = cf_upload_to_drive($filepath, $filename);
            cf_write_to_google_sheet($nome, $email, $link_cv);
            $event = cf_create_calendar_event($nome, $email);
            $link_evento = $event ? $event->htmlLink : 'Nessun evento disponibile';

            // Email a info@
            wp_mail('info@gattipc.it', 'Nuova candidatura ricevuta', 
                "Nome: $nome\nEmail: $email\nCV: $link_cv\nColloquio: $link_evento",
                ['Content-Type: text/plain; charset=UTF-8']);

            // Email al candidato
            wp_mail($email, 'Candidatura ricevuta', 
                "Ciao $nome,\nGrazie per la tua candidatura.\nCV: $link_cv\nColloquio: $link_evento",
                ['Content-Type: text/plain; charset=UTF-8']);

            echo '<div>Grazie! Abbiamo ricevuto la tua candidatura.</div>';
        } else {
            echo '<div>Errore durante il caricamento del file.</div>';
        }
        exit;
    }
}

function cf_upload_to_drive($filepath, $filename) {
    require_once __DIR__ . '/vendor/autoload.php';
    $client = new Google_Client();
    $client->setAuthConfig(__DIR__ . '/cf-service-key.json');
    $client->addScope(Google_Service_Drive::DRIVE);
    $service = new Google_Service_Drive($client);

    $fileMetadata = new Google_Service_Drive_DriveFile([
        'name' => $filename,
        'parents' => ['ID_CARTELLA_DRIVE']
    ]);
    $content = file_get_contents($filepath);
    $file = $service->files->create($fileMetadata, [
        'data' => $content,
        'mimeType' => mime_content_type($filepath),
        'uploadType' => 'multipart',
        'fields' => 'id, webViewLink'
    ]);
    return $file->getWebViewLink();
}

function cf_write_to_google_sheet($nome, $email, $link_cv) {
    require_once __DIR__ . '/vendor/autoload.php';
    $client = new Google_Client();
    $client->setAuthConfig(__DIR__ . '/cf-service-key.json');
    $client->addScope(Google_Service_Sheets::SPREADSHEETS);
    $service = new Google_Service_Sheets($client);

    $spreadsheetId = 'ID_DEL_TUO_SHEET';
    $range = 'Foglio1!A:E';
    $data = [[ $nome, $email, date('Y-m-d H:i:s'), $link_cv, 'NO' ]];
    $body = new Google_Service_Sheets_ValueRange(['values' => $data]);
    $params = ['valueInputOption' => 'RAW'];
    $service->spreadsheets_values->append($spreadsheetId, $range, $body, $params);
}

function cf_create_calendar_event($nome, $email) {
    require_once __DIR__ . '/vendor/autoload.php';
    $client = new Google_Client();
    $client->setAuthConfig(__DIR__ . '/cf-service-key.json');
    $client->addScope(Google_Service_Calendar::CALENDAR);
    $service = new Google_Service_Calendar($client);

    $calendarId = 'primary';
    $today = date('Y-m-d');
    $startHour = 15;
    $endHour = 17;

    for ($dayOffset = 0; $dayOffset < 10; $dayOffset++) {
        $date = date('Y-m-d', strtotime("+$dayOffset days"));
        $weekday = date('N', strtotime($date));
        if ($weekday > 5) continue; // Salta weekend

        for ($h = $startHour; $h < $endHour; $h++) {
            $startTime = "$date" . "T" . str_pad($h, 2, '0', STR_PAD_LEFT) . ":00:00";
            $endTime = "$date" . "T" . str_pad($h+1, 2, '0', STR_PAD_LEFT) . ":00:00";

            $events = $service->events->listEvents($calendarId, [
                'timeMin' => $startTime . 'Z',
                'timeMax' => $endTime . 'Z',
                'singleEvents' => true,
                'orderBy' => 'startTime'
            ]);

            if (count($events->getItems()) == 0) {
                $event = new Google_Service_Calendar_Event([
                    'summary' => "Colloquio con $nome",
                    'description' => "Email: $email",
                    'start' => ['dateTime' => $startTime . '+02:00'],
                    'end' => ['dateTime' => $endTime . '+02:00'],
                    'attendees' => [['email' => $email]],
                ]);
                return $service->events->insert($calendarId, $event);
            }
        }
    }
    return null;
}
?>
