<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Entity.php');
require_once(IZNIK_BASE . '/include/chat/ChatRoom.php');

use GeoIp2\Database\Reader;
use LanguageDetection\Language;

class WorryWords {
    CONST TYPE_REGULATED = 'Regulated';     // UK regulated substance
    CONST TYPE_REPORTABLE = 'Reportable';   // UK reportable substance
    CONST TYPE_MEDICINE = 'Medicine';       // Medicines/supplements.

    /** @var  $dbhr LoggedPDO */
    private $dbhr;

    /** @var  $dbhm LoggedPDO */
    private $dbhm;

    private $words = NULL;

    function __construct($dbhr, $dbhm, $id = NULL)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->log = new Log($this->dbhr, $this->dbhm);
    }

    public function checkMessage($id, $fromuser, $subject, $textbody, $log = TRUE) {
        $this->getWords();
        $ret = NULL;

        foreach ([ $subject, $textbody ] as $scan) {
            $words = preg_split("/[\s,]+/", $scan);

            foreach ($words as $word) {
                foreach ($this->words as $worryword) {
                    if (levenshtein(strtolower($worryword['keyword']), strtolower($word)) < 2) {
                        # Close enough to be worrying.
                        if ($log) {
                            $this->log->log([
                                'type' => Log::TYPE_MESSAGE,
                                'subtype' => Log::SUBTYPE_WORRYWORDS,
                                'user' => $fromuser,
                                'msgid' => $id,
                                'text' => "Found {$worryword['keyword']} type {$worryword['type']} in $word"
                            ]);
                        }

                        if ($ret === NULL) {
                            $ret = [];
                        }

                        $ret[] = [
                            'word' => $word,
                            'worryword' => $worryword,
                        ];
                    }
                }
            }
        }

        return($ret);
    }

    private function getWords() {
        if (!$this->words) {
            $this->words = $this->dbhr->preQuery("SELECT * FROM worrywords;");
        }
    }
}