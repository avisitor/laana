<?php
include '../db/parsehtml.php';
$parser = new CBHtml();

$url = 'https://www.civilbeat.org/2022/05/%CA%BBo-na-mea-a-ka-limu-e-ho%CA%BBike-aku-ai-e-pili-ana-i-ko-kakou-%CA%BBaina-ma-hawai%CA%BBi/';
$dom = $parser->fetch( $url );
$date = $parser->extractDate( $dom );
echo "$date\n";
return;

$text = 'Kā ka luna hoʻoponopono nota: Unuhi ʻia na Ākea Kahikina. Click here to read this article in English.   Hoihoi anei ʻoe e hoʻolohe i nā mea ʻē aʻe? E hahai iā �Stemming The Tide� a nānā i kā Civil Beat mau pūkaʻina kūkā ʻē aʻe.  ʻO seaweed, ʻo macroalgae, a ʻo kelp � nui nā inoa like ʻole no nā lāʻau o ke kai, akā ma Hawaiʻi, ʻo limu kona inoa.  Ma mua o ka hōʻea ʻana mai o nā haole komohana, he koʻikoʻi ka lumi ma ka moʻomeheu a me ka nohona Hawaiʻi. ʻO ka limu he ʻai, he lāʻau lapaʻau, he mea lei, a he mea hoʻowaihoʻoluʻu i ke kapa. ʻO kekahi ʻano ʻo ka limu kala, he mea ia ma nā hana hoʻoponopono � he ʻaha ia e hoʻonā ʻia ai ka hihia o ka naʻau � i mea e noi kala ʻia ai nā kānaka ma ka ʻaha ma o ka hoʻopaʻa a me ka ʻai ʻana i ka lāʻau.  Ma ke ʻano he kahua ia no ka ʻōnaehana ʻai o nā holoholona kai, he mea nui ka limu ma nā kaiaola moana, ʻoiai, nāna e hānai a kiaʻi i nā holoholona iwi kuamoʻo ʻole liʻiliʻi a me nā holoholona ʻai lāʻau.  Akā naʻe, ma o kēlā mau kekeke aku nei, ua hoʻopilikia nui ʻia nā limu ʻōiwi like ʻole ma ko Hawaiʻi mau kai. ʻO ke kūkulu hale, ka hoʻohaumia wai honua, nā lāhui limu haole a me ka hoʻohuli aniau, ua lilo pū kēia mau mea i pilikia nui no ka limu.  ʻO Veronica Gibson, he haumāna laeʻula ʻo ia ma ke Kulanui o Hawaiʻi ma Mānoa, a e noiʻi limu ana ʻo ia no nā makahiki he 10 a ʻoi. Nāna kā i ʻōlelo, aia kākou ma kinohi o ka hoʻomaopopo leʻa ʻana i ia mea. ʻO ka mea mōakākā naʻe, ʻo ia ke kuleana a ka poʻe e ʻauamo aku ana no ka mālama ʻana i ka wā e hiki mai ana no ka limu.  ʻO kākou kānaka nā wilikī kaiaola e koho i nā mea e uluāhewa a me ke ʻano o kā kākou hopena ma kēia mau kaiaola, wahi āna.  Paʻa ko Gibson manaʻo, inā hoʻonui ʻia ka helu o nā kānaka i kamaʻāina i ka hiʻona o nā kaiaola ʻōiwi, e hiki ana iā lākou ke haʻilono i nā loli maʻamau ʻole.';
//$text = $parser->prepareRaw( $text );
        $text = str_replace( '&nbsp;', ' ', $text );
        $text = preg_replace( '/\s*\<br\s*\\*\>/', '\n', $text );
        $text = preg_replace( '/["“”\\n]/', '', $text );
        // Restore the removed Ā
        $text = str_replace( $parser->Amarker, 'Ā', $text );
$text = preg_replace( '/\xc2\xa0/', ' ', $text );

echo "After prepareRaw: $text\n";
$lines = $parser->processText( $text );
foreach( $lines as $text ) {
    echo "$text\n" . bin2hex( $text ) . "\n";
}
    //debuglog( $lines );
return;

/*
$text = "Ua kākoʻo ʻia kēia papahana e ka ʻOhana o Harry Nathaniel, Levani Lipton, ka ʻOhana Mar, a me Lisa Kleissner.";
$newtext = $parser->prepareRaw( $text );
echo "CBHtml::process after prepareRaw: " . $newtext . "\n";
return;
*/

//$pages = $parser->getPageList();
//var_export( $ );

$url = "https://keaolama.org/2022/05/03/05-02-22/";
$url = "https://www.civilbeat.org/2022/05/hoʻoulu-ka-pani-alahele-ma-ke-awawa-o-waipiʻo-i-ka-huliamahi-o-ke-kaiaulu-no-ka-paipai-kanawai/";
$url = "https://www.civilbeat.org/2022/04/niele-%ca%bbia-ka-kai-kahele-%ca%bboihana-ma-hawaiian-airlines/";

$url = 'https://www.civilbeat.org/2022/05/%CA%BBo-na-mea-a-ka-limu-e-ho%CA%BBike-aku-ai-e-pili-ana-i-ko-kakou-%CA%BBaina-ma-hawai%CA%BBi/';
$sentences = $parser->extractSentences( $url );
var_export( $sentences );
?>
