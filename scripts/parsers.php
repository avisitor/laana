<?php
$parsermap = [
    'kaulanapilina' => new CBHtml(),
    //'ulukau' => new UlukauHTML(),
    'ulukau' => new UlukauHTML(),
    'ulukaulocal' => new UlukauLocal(),
    'keaolama' => new AoLamaHTML(),
    'kauakukalahale' => new KauakukalahaleHTML(),
    'nupepa' => new NupepaHTML(),
    'kapaamoolelo' => new KaPaaMooleloHTML(),
    'baibala' => new BaibalaHTML(),
    'ehooululahui' => new EhoouluLahuiHTML(),
    'kaiwakiloumoku' => new KaiwakiloumokuHTML(),
];
$urlmap = [
    'kaulanapilina' => 'https://www.civilbeat.org/2022/05/%CA%BBo-na-mea-a-ka-limu-e-ho%CA%BBike-aku-ai-e-pili-ana-i-ko-kakou-%CA%BBaina-ma-hawai%CA%BBi/',
    //'ulukau' => "https://puke.ulukau.org/ulukau-books/?a=d&d=EBOOK-HK2&e=-------en-20--1--txt-txPT-----------",
    'ulukau' => "https://puke.ulukau.org/ulukau-books/?a=d&d=EBOOK-MAKANA&e=-------en-20--1--txt-txPT-----------",
    'ulukaulocal' => "/webapps/worldspot.com/worldspot/render-proxy/output/EBOOK-APLC01.txt",
    'keaolama' => "https://keaolama.org/2022/05/03/05-02-22/",
    'kauakukalahale' => 'https://www.staradvertiser.com/2023/10/21/editorial/kauakukalahale/column-e-hoaei-paha-i-ke-one-o-luhi/',
    'nupepa' => "https://nupepa.org/?a=d&d=KLH18340214-01",
    'kapaamoolelo' => 'https://www2.hawaii.edu/~kroddy/moolelo/kalelealuaka/helu1.htm',
    'baibala' => 'https://baibala.org/cgi-bin/bible?e=d-1off-01994-bible--00-1-0--01994v2-0--4--Sec---1--1en-Zz-1-other---20000-frameset-search-browse----011-01994v1--210-0-2-escapewin&cl=&d=NULL.2.1.1&cid=&bible=&d2=1&toc=0&gg=text#a1-',
    'ehooululahui' => 'https://ehooululahui.maui.hawaii.edu/?page_id=67',
];
$parserkey = null;
?>
