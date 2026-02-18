<?php
namespace local_helios_notify;
defined('MOODLE_INTERNAL') || die();

class observer {


    /**
     * Trigger: dokončení kurzu.
     */
    public static function course_completed(\core\event\course_completed $event) {
        $userid   = (int)$event->relateduserid;
        $courseid = (int)$event->courseid;

        if (!$userid || !$courseid) {
            return true;
        }

        // Odeslat pouze tehdy, pokud ještě NEBYLO odesláno.
        self::send_once_for_user_course($userid, $courseid);

        return true;
    }

    // ---------------------------------------------------------------------
    //                    Logika a utility
    // ---------------------------------------------------------------------

    /**
     * Odeslat do Heliosu právě 1× pro danou dvojici (uživatel, kurz).
     * Kontroluje a nastavuje uživatelskou preferenci: local_helios_notify_sent_{courseid} = 1
     */
    private static function send_once_for_user_course(int $userid, int $courseid): void {
        // Klíč idempotence: uložený u uživatele. Nevyžaduje vlastní DB schemata.
        $prefkey = 'local_helios_notify_sent_'.$courseid;

        // Již odesláno? → konec.
        if (get_user_preferences($prefkey, 0, $userid)) {
            return;
        }

        // Pokus o odeslání. Při úspěchu nastavíme preferenci.
        $ok = self::send_to_helios_by_user_course($userid, $courseid);
        if ($ok) {
            set_user_preference($prefkey, 1, $userid);
        }
    }

    /**
     * Sestaví data a pošle do Heliosu.
     * Vrací true/false podle toho, zda se podařilo provést alespoň LogOn + ProcessXml + LogOff bez fatální chyby.
     */
    private static function send_to_helios_by_user_course(int $userid, int $courseid): bool {
        global $DB;

        // 1) os_cis z uživatelské custom field (fieldid=2).
        $os_cis = $DB->get_field('user_info_data', 'data', ['userid' => $userid, 'fieldid' => 2], IGNORE_MISSING);
        if (empty($os_cis)) {
            return false;
        }

        // 2) školení/identifikátor – shortname kurzu.
        $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
        if (!$course) {
            return false;
        }
        $skol_ref = (string)$course->shortname;

        // 3) Konfigurace pluginu.
        $endpoint   = (string)get_config('local_helios_notify','endpoint');
        $profile    = (string)get_config('local_helios_notify','profile');
        $username   = (string)get_config('local_helios_notify','username');
        $password   = (string)get_config('local_helios_notify','password');
        $functionid = (string)get_config('local_helios_notify','functionid');

        if (!$endpoint || !$username || !$password || !$functionid) {
            return false;
        }

        // 4) LOGON
        $logon_xml = <<<XML
<web:LogOn xmlns:web="http://lcs.cz/webservices/">
    <web:profile>{$profile}</web:profile>
    <web:username>{$username}</web:username>
    <web:password>{$password}</web:password>
    <web:language>CZ</web:language>
    <web:options></web:options>
</web:LogOn>
XML;

        $logon_response = self::send_soap(
            $endpoint,
            $logon_xml,
            "http://lcs.cz/webservices/LogOn"
        );

        $token = self::extract_token($logon_response);
        if (empty($token)) {
            return false;
        }

        // 5) RUN XML – kompaktní bez whitespace.
        $run_xml =
            '<RUN FUNCTIONID="'.$functionid.'" auditlogDetail="warning">' .
            '<params><rows><row>' .
            '<os_cis>'.$os_cis.'</os_cis>' .
            '<skol_ref>'.$skol_ref.'</skol_ref>' .
            '</row></rows></params></RUN>';

        // 6) PROCESS XML
        $process_xml = <<<XML
<web:ProcessXml xmlns:web="http://lcs.cz/webservices/">
    <web:sessionToken>{$token}</web:sessionToken>
    <web:inputXml><![CDATA[$run_xml]]></web:inputXml>
</web:ProcessXml>
XML;

        self::send_soap(
            $endpoint,
            $process_xml,
            "http://lcs.cz/webservices/ProcessXml"
        );

        // 7) LOGOFF (bez ohledu na výsledek ProcessXml se pokusíme korektně ukončit relaci)
        $logoff_xml = <<<XML
<web:LogOff xmlns:web="http://lcs.cz/webservices/">
    <web:sessionToken>{$token}</web:sessionToken>
</web:LogOff>
XML;

        self::send_soap(
            $endpoint,
            $logoff_xml,
            "http://lcs.cz/webservices/LogOff"
        );

        return true;
    }

    // ---------------------------------------------------------------------
    //                         SOAP / XML utility
    // ---------------------------------------------------------------------

    /**
     * Nízkourovňové odeslání SOAP 1.1 s daným SOAPAction.
     */
    private static function send_soap(string $url, string $body_xml, string $action): string {
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
        // V produkci doporučuji zapnout ověřování certifikátu a mít správná CA:
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $res = curl_exec($ch);
        curl_close($ch);
        return (string)$res;
    }

    /**
     * Extrakce tokenu z LogOnResponse (defaultní namespace "http://lcs.cz/webservices/").
     */
    private static function extract_token(string $xml): ?string {
        $sx = @simplexml_load_string($xml);
        if ($sx) {
            $namespaces = $sx->getNamespaces(true);
            foreach ($namespaces as $prefix => $uri) {
                if ($uri === "http://lcs.cz/webservices/") {
                    $sx->registerXPathNamespace('web', $uri);
                    $nodes = $sx->xpath('//web:LogOnResult');
                    if ($nodes && trim((string)$nodes[0]) !== '') {
                        return trim((string)$nodes[0]);
                    }
                }
            }
        }

        if (preg_match('/\d+,\d+/', $xml, $m)) {
            return $m[0];
        }
        return null;
        }
}