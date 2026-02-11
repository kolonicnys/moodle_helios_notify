<?php
require('../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/helios_notify/test.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Helios – Test SOAP komunikace');
$PAGE->set_heading('Helios – Test SOAP komunikace');

echo $OUTPUT->header();

$os_cis = optional_param('os_cis', '', PARAM_RAW);
$skol_ref = optional_param('skol_ref', '', PARAM_RAW);
$send = optional_param('send', false, PARAM_BOOL);

if ($send && $os_cis && $skol_ref) {

    echo html_writer::tag('h3', 'Výsledek odeslání');

    // Načtení konfigurace
    $endpoint  = get_config('local_helios_notify','endpoint');
    $profile   = get_config('local_helios_notify','profile');
    $username  = get_config('local_helios_notify','username');
    $password  = get_config('local_helios_notify','password');
    $functionid = get_config('local_helios_notify','functionid');


// --- LOGON ---
$logon_xml = <<<XML
<web:LogOn xmlns:web="http://lcs.cz/webservices/">
    <web:profile>{$profile}</web:profile>
    <web:username>{$username}</web:username>
    <web:password>{$password}</web:password>
    <web:language>CZ</web:language>
    <web:options></web:options>
</web:LogOn>
XML;

$logon_response = local_helios_notify_send_soap(
    $endpoint,
    $logon_xml,
    "http://lcs.cz/webservices/LogOn"
);

echo "<strong>LogOn response:</strong><pre>".htmlspecialchars($logon_response)."</pre>";

// --- TOKEN ---
$token = local_helios_notify_extract_token($logon_response);

echo "<strong>Token:</strong> ".htmlspecialchars($token)."<br><br>";

if ($token) {

    // --- RUN XML ---
    $run_xml =
        '<RUN FUNCTIONID="'.$functionid.'" auditlogDetail="warning">' .
        '<params><rows><row>' .
        '<os_cis>'.$os_cis.'</os_cis>' .
        '<skol_ref>'.$skol_ref.'</skol_ref>' .
        '</row></rows></params></RUN>';

    // --- PROCESS XML ---
    $process_xml = <<<XML
<web:ProcessXml xmlns:web="http://lcs.cz/webservices/">
    <web:sessionToken>{$token}</web:sessionToken>
    <web:inputXml><![CDATA[$run_xml]]></web:inputXml>
</web:ProcessXml>
XML;

    $process_response = local_helios_notify_send_soap(
        $endpoint,
        $process_xml,
        "http://lcs.cz/webservices/ProcessXml"
    );

    echo "<strong>ProcessXml response:</strong><pre>".htmlspecialchars($process_response)."</pre>";

    // --- LOGOFF ---
    $logoff_xml = <<<XML
<web:LogOff xmlns:web="http://lcs.cz/webservices/">
    <web:sessionToken>{$token}</web:sessionToken>
</web:LogOff>
XML;

    $logoff_response = local_helios_notify_send_soap(
        $endpoint,
        $logoff_xml,
        "http://lcs.cz/webservices/LogOff"
    );

    echo "<strong>LogOff response:</strong><pre>".htmlspecialchars($logoff_response)."</pre>";
}

}

// FORMULÁŘ
echo '<h3>Testovací odeslání do ERP</h3>';

echo '<form method="post">';
echo 'os_cis: <input type="text" name="os_cis" value="'.htmlspecialchars($os_cis).'" required><br><br>';
echo 'skol_ref: <input type="text" name="skol_ref" value="'.htmlspecialchars($skol_ref).'" required><br><br>';
echo '<input type="submit" name="send" value="Odeslat do ERP">';
echo '</form>';

echo $OUTPUT->footer();


// =====================================================
// Funkce SOAP – stejné jako v observer.php
// =====================================================

function local_helios_notify_send_soap($url, $body_xml, $action) {
    $envelope = <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
  <soapenv:Header/>
  <soapenv:Body>
    {$body_xml}
  </soapenv:Body>
</soapenv:Envelope>
XML;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $envelope);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: text/xml; charset=UTF-8",
        "Accept: text/xml",
        "SOAPAction: \"{$action}\"",
        "Connection: Keep-Alive"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}


function local_helios_notify_extract_token($xml) {

    // 1) POKUS o XML parsing
    $sx = @simplexml_load_string($xml);
    if ($sx) {
        // Najdeme všechny nody obsahující namespace
        $namespaces = $sx->getNamespaces(true);

        // Najdeme defaultní namespace (LogOnResponse ho používá!)
        foreach ($namespaces as $prefix => $uri) {
            if ($uri === "http://lcs.cz/webservices/") {
                $sx->registerXPathNamespace('web', $uri);

                // Pokus o extrakci přes XPath
                $nodes = $sx->xpath('//web:LogOnResult');
                if ($nodes && trim((string)$nodes[0]) !== '') {
                    return trim((string)$nodes[0]);
                }
            }
        }
    }

    // 2) BEZPEČNÝ FALLBACK: hledej token jako "číslo,číslo"
    if (preg_match('/\\d+,\\d+/', $xml, $m)) {
        return $m[0];
    }

    return null;
}