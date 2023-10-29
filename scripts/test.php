<?php
$text = '  “A inā ʻaʻole ʻo Keawemauhili kou inoa, a laila, ʻo wai lā hoʻi kou inoa, e kēia kanaka kūlana aliʻi?” “ ʻO Keaweʻōpala koʻu inoa, a ʻaʻole hoʻi ʻo Keawemauhili.” Ia manawa koke nō i pane koke mai ai kekahi wahi kanaka i waena o kēlā poʻe e kū mai ana a ʻākeʻakeʻa i ko lākou nei alahele, “ ʻEā, ua pololei ka ʻōlelo a kēlā aliʻi, ʻo Keaweʻōpala ʻiʻo nō kēia, ʻoiai, aia nō hoʻi ka pōpō ʻōpala ke kau maila i kona maka hema. E hoʻokuʻu kākou iā ia nei me kāna ʻohana, a ʻaʻole hoʻi e kau aku ko kākou mau lima ma luna o ia nei, o lohe aku auaneʻi ʻo Paiʻea i kēia hana a kākou, a papapau kākou i ka make ma muli o ka limanui ʻana i ko ia ala hulu makua kāne.” Ma muli o kēlā ʻōlelo a kēlā wahi koa i kona poʻe, ua ʻano kau ʻiʻo maila ke ʻano makaʻu i waena o kēia mau koa lanakila, a ʻo ko lākou hoʻokaʻawale aʻela nō ia i ke alahele no lākou nei e hele aku nei. Hala aʻela hoʻi kēia puʻumake o lākou nei, akā, ʻaʻole ia he kumu e hoʻopau ai ʻo Kapiʻolani i ka uē ʻana, a ua lilo ihola i mea hoʻokaumaha loa i ka manaʻo o Keawemauhili, ʻoiai, ua ʻike ihola nō ʻo ia i ko lākou pōʻino ma muli o kēia hana mau o ke kaikamahine i ka uē, a lilo ʻiʻo nō paha ia i mea kāhea aku i ka poʻe koa lanakila e ʻimi nei i loko o kēia wao kele i ka poʻe pio.';
$text = 'A inā ʻaʻole ʻo Keawemauhili kou inoa, a laila, ʻo wai lā hoʻi kou inoa, e kēia kanaka kūlana aliʻi? ʻO Keaweʻōpala koʻu inoa, a ʻaʻole hoʻi ʻo Keawemauhili. Ia manawa koke nō i pane koke mai ai kekahi wahi kanaka i waena o kēlā poʻe e kū mai ana a ʻākeʻakeʻa i ko lākou nei alahele, ʻEā, ua pololei ka ʻōlelo a kēlā aliʻi, ʻo Keaweʻōpala ʻiʻo nō kēia, ʻoiai, aia nō hoʻi ka pōpō ʻōpala ke kau maila i kona maka hema.';
$lines = preg_split('/(?<=[.?!])\s*/', $text, -1, PREG_SPLIT_NO_EMPTY);
//$lines = preg_split('/[.?!]/g', $text, -1, PREG_SPLIT_NO_EMPTY);
//$lines = preg_split('/[.?!]/g', $text, -1);
//$lines = preg_split('/[\s]+/', $text, -1);
//echo "$text\n";
echo( var_export( $lines, true ) . "\n" );
//print_r( $lines );
$lines = preg_split( '(?<=[!?.])(?:$|\s+(?=\p{Lu}\p{Ll}*\b))', $text, -1, PREG_SPLIT_NO_EMPTY );
echo( var_export( $lines, true ) . "\n" );

?>
