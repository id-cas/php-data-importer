<?php
/**
 * cMail - помогает создать письмо в формате html
 */

class Mail {
    private static $mail = null;

    private $to = '';
    private $from = '';
    private $reply = '';
    private $subject = '';

	public function __construct($ops){
        $this->to = $ops['to'];
        $this->from = $ops['from'];
        $this->reply = $ops['reply'];
        $this->subject = $ops['subject'];
    }

    public function send($msg){
        // $subject = "=?utf-8?B?" . base64_encode($subject) . "?=";
        $headers="Content-type: text/plain; charset=utf-8\n";
        $headers.="X-Priority: 3\n";
        $headers.="Content-Transfer-Encoding: 8bit\n";
        $headers.="From: ". $this->from. "\n";
        $headers.="Reply-To: ". $this->reply. "\n";
        $headers.='X-Mailer: PHP/' . phpversion();
        $headers.="\n";

        $destinations = explode(",", $this->to);
		foreach($destinations as $to){
			mail($to, $this->subject, $msg, $headers);
		}
    }
}
