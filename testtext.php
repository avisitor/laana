<?php
include 'db/p.php';
$parser = new TextParse();

$text = 'MOKUNA I

‘O kēia mo‘olelo o ‘Aukelenuia‘īkū, ‘o ia kekahi o nā mo‘olelo kaulana loa ma Hawai‘i nei. ‘O Kuaihelani ka ‘āina. ‘O ‘Īkū ke kāne, he ali‘i. ‘O Kapapaiākea ka wahine. Na lāua nā keiki he ‘umikumamālua. E ho‘omaka ana ka ‘ōlelo ma Kuaihelani. Eia nā inoa o nā keiki: Kekamakahinuia‘īkū, Kūa‘īkū, Nohoa‘īkū, Helea‘īkū, Kapukapua‘īkū, Heaa‘īkū, Lonohea‘īkū, Nāa‘īkū, Noia‘īkū, ‘Īkūmailani me ‘Aukelenuia‘īkū. He mau kāne, a me Kaomeaa‘īkū, he wahine. ‘O ‘Aukelenuia‘īkū ka mea nona kēia mo‘olelo.

Mai ka hiapo a ka mua pono‘ī o ‘Aukelenuia‘īkū, ‘a‘ole ‘o ‘Īkū i hi‘i, ‘a‘ole i lawelawe, ‘a‘ole ho‘i i ho‘oili i ka ‘āina no kekahi o lākou. ‘A‘ole nō ho‘i i ho‘opunahele. A iā ‘Aukelenuia‘īkū, mālama ‘o ‘Īkū, lawelawe a hi‘i, a ho‘oili i kona kapu a me ka ‘āina nona. A no kēia punahele ‘o ‘Aukelenuia‘īkū i ko lākou makuakāne, ua huhū kona mau hoahānau iā ia, a ua ‘imi lākou i mea nona e make ai. Wahi a ko lākou kaikua‘ana loa, a Kamakahinuia‘īkū, “Kupanaha ko kākou makuakāne! Ia‘u ho‘i, i ke keiki mua, ‘a‘ole i ho‘oili mai i kona kapu a me ka ‘āina. A i ke keiki hope loa, iā ia kā e ho‘oili ai!”

‘O ka hana nui a nā kaikua‘ana o ‘Aukelenuia‘īkū, ‘o ka mokomoko, ‘o ka hākōkō, ke ku‘iku‘i, a me nā mea ikaika ‘ē a‘e. A ma kēia mea, ua lilo lākou he po‘e kaulana no Kuaihelani ma kēia hana. A ‘o lākou ka ‘oi o ka ikaika ma ia hana. A ua hele lākou e ka‘apuni ma ka ‘āina a puni, ‘a‘ole mea ‘a‘a mai iā lākou. Iā lākou e ka‘apuni ana i ka ‘āina ‘o Kuaihelani, kaulana akula ka ikaika o Ke‘alohikīkaupe‘a. No Kaua‘i ia kanaka. ‘O kona ikaika, he uhaki wale nō i ke kanaka. A hiki lākou nei i laila, ho‘okahi nō pu‘upu‘u, waiho ana i lalo. Ka‘apuni lākou a puni ‘o Kaua‘i, ‘a‘ohe mea ‘a‘a mai iā lākou.

Iā lākou nei ma Kaua‘i, ku‘i akula ka lohe, ‘ekolu o O‘ahu kanaka ikaika loa. ‘O ko lākou mau inoa, ‘o Kaikipa‘ananea, ‘o Kupukupukehaikalani, ‘o Kupukupukehaia‘īkū. ‘A‘ohe pū kō momona o O‘ahu nei iā lākou. A hiki lākou i O‘ahu nei, hākōkō ihola lākou. Ho‘okahi nō pu‘upu‘u, waiho ana ua mau kānaka ala o O‘ahu nei i lalo. A ha‘alele ihola lākou, hele lākou a Maui. E noho ana ‘o Kāka‘alaneo, ke ali‘i o Maui. Hākōkō nō, make nō iā lākou nei.

Iā lākou ma Maui e ka‘apuni ana, kaulana maila ‘o Kepaka‘ili‘ula i ka ikaika a me ke koa. E hiki iā ia ke ha‘iha‘i i ke kanaka, a ‘o ia ka ‘oi o Hawai‘i a

1

puni. A lohe lākou i kona kaulana i ka ikaika, maka‘u ihola lākou a ho‘i maila i Kuaihelani. A hiki lākou i Kuaihelani, kūkulu ihola lākou i nā hana le‘ale‘a a pau loa; ka hākōkō, ka mokomoko, ke ku‘iku‘i, ka honuhonu, ka pūhenehene, ka hula, ‘olohū, ka lele kawa, ka pahe‘e, a me nā hana ‘ē a‘e. Ma kēia mau hana le‘ale‘a a lākou a pau loa, ua ho‘oulu lākou i mea e hele mai ai ko lākou kaikaina ‘o ‘Aukelenuia‘īkū, a laila, pepehi lākou iā ia a make. No ka mea, ‘o ‘Aukelenuia‘īkū, ua pa‘a loa ia i ka pālama ‘ia e ko lākou makuakāne, e ‘Īkū, ma ke ‘ano kapu ali‘i, a me ka punahele loa.

‘O ia ka pau ‘ana o kēia mo‘olelo.

65';

//$text = preg_replace( '/\xe2\x80\x98/u', '', $text );
//$text = preg_replace( '/‘/', 'yYyYyY', $text );
$sentences = $parser->getSentences( $text );
var_export( $sentences );
?>
