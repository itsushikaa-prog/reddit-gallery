<?php
$subreddit = $_GET['subreddit'] ?? 'czskkurvy';
$sort = $_GET['sort'] ?? 'new';
$votesFile = __DIR__.'/votes.json';

// === HLASY ===
function loadVotes(){
    global $votesFile;
    if(!file_exists($votesFile)) return [];
    $j = file_get_contents($votesFile);
    return json_decode($j,true) ?? [];
}
function toggleVote($p){
    global $votesFile;
    $v = loadVotes();
    $v[$p] = isset($v[$p]) && $v[$p]==1 ? 0 : 1;
    file_put_contents($votesFile,json_encode($v));
    return $v[$p];
}
if(isset($_GET['like'])){
    header('Content-Type:application/json');
    echo json_encode(['state'=>toggleVote($_GET['like'])]);
    exit;
}

// === Načtení Redditu přímo (Render má čistou IP) ===
function getRedditPosts($subreddit,$sort,$after=null,$limit=10){
    $url="https://www.reddit.com/r/$subreddit/$sort.json?limit=$limit";
    if($after)$url.="&after=$after";
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_USERAGENT=>"Mozilla/5.0 (Windows NT 10.0; RedditProxy/1.0)",
        CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_TIMEOUT=>10
    ]);
    $r=curl_exec($ch);
    $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $err=curl_error($ch);
    curl_close($ch);
    if($code!==200||!$r){
        file_put_contents(__DIR__.'/debug_log.txt',"URL:$url\nHTTP:$code\nERR:$err\nLEN:".strlen($r)."\n\n",FILE_APPEND);
        return null;
    }
    $j=json_decode($r,true);
    return isset($j['data']['children'])?$j:null;
}

// === Vytažení médií ===
function extractMedia($p){
    $m=[]; $d=$p['data'];
    if(isset($d['is_gallery'])&&isset($d['media_metadata'])){
        foreach($d['media_metadata'] as $x){
            $u=$x['s']['u']??null;
            if($u)$m[]=['type'=>'img','src'=>str_replace('&amp;','&',$u)];
        }
    }elseif(isset($d['secure_media']['reddit_video'])){
        $v=$d['secure_media']['reddit_video']['fallback_url']??null;
        if($v)$m[]=['type'=>'video','src'=>$v];
    }elseif(isset($d['url'])){
        $u=$d['url'];
        $ext=strtolower(pathinfo(parse_url($u,PHP_URL_PATH),PATHINFO_EXTENSION));
        if(in_array($ext,['jpg','jpeg','png','gif','webp']))$m[]=['type'=>'img','src'=>$u];
        if(in_array($ext,['mp4','webm','mov','m4v']))$m[]=['type'=>'video','src'=>$u];
    }
    return $m;
}

// === Načti data ===
$data=getRedditPosts($subreddit,$sort);
$votes=loadVotes(); $posts=[];
if($data){
    foreach($data['data']['children'] as $ch){
        $media=extractMedia($ch);
        if($media){
            $link='https://reddit.com'.$ch['data']['permalink'];
            $posts[]=[
                'title'=>$ch['data']['title'],
                'author'=>$ch['data']['author'],
                'link'=>$link,
                'media'=>$media,
                'liked'=>$votes[$link]??0
            ];
        }
    }
}else{$error="❌ Nelze načíst příspěvky – Reddit odmítl spojení (zkontroluj subreddit).";}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Reddit Galerie</title>
<style>
body{background:#121212;color:#eee;font-family:Segoe UI,Arial;margin:0;padding:20px;text-align:center}
header{margin-bottom:20px}
.post{background:#1e1e1e;border-radius:10px;margin:20px auto;padding:10px;max-width:800px}
.media img,.media video{max-width:320px;border-radius:8px;margin:5px}
.like{cursor:pointer;font-size:1.3em}
.error{background:#c00;color:#fff;padding:10px;border-radius:8px;max-width:600px;margin:20px auto}
</style>
</head>
<body>
<header>
<h1>📸 Reddit Galerie</h1>
<select id="subreddit">
<option value="czskkurvy"<?= $subreddit=='czskkurvy'?' selected':''?>>r/czskkurvy</option>
<option value="holkycz_sk"<?= $subreddit=='holkycz_sk'?' selected':''?>>r/holkycz_sk</option>
<option value="cz_sk_holky_"<?= $subreddit=='cz_sk_holky_'?' selected':''?>>r/cz_sk_holky_</option>
<option value="influencerky_cze"<?= $subreddit=='influencerky_cze'?' selected':''?>>r/influencerky_cze</option>
</select>
<select id="sort">
<option value="new"<?= $sort=='new'?' selected':''?>>Nejnovější</option>
<option value="hot"<?= $sort=='hot'?' selected':''?>>Hot</option>
<option value="top"<?= $sort=='top'?' selected':''?>>Top</option>
</select>
</header>

<?php if(isset($error)): ?>
<div class="error"><?=htmlspecialchars($error)?></div>
<?php else: foreach($posts as $p): ?>
<div class="post">
  <h2><?=htmlspecialchars($p['title'])?></h2>
  <div>Autor: <?=htmlspecialchars($p['author'])?></div>
  <a href="<?=$p['link']?>" target="_blank">Původní příspěvek</a>
  <div class="media">
  <?php foreach($p['media'] as $m): ?>
    <?php if($m['type']=='img'): ?><img src="<?=$m['src']?>"><?php endif; ?>
    <?php if($m['type']=='video'): ?><video src="<?=$m['src']?>" controls muted></video><?php endif; ?>
  <?php endforeach; ?>
  </div>
  <div class="like" data-post="<?=$p['link']?>"><?=$p['liked']?'❤️':'🤍'?></div>
</div>
<?php endforeach; endif; ?>

<script>
document.getElementById('subreddit').onchange=()=>location='?subreddit='+event.target.value+'&sort=<?=$sort?>';
document.getElementById('sort').onchange=()=>location='?subreddit=<?=$subreddit?>&sort='+event.target.value;
document.addEventListener('click',e=>{
 if(e.target.classList.contains('like')){
   fetch('?like='+encodeURIComponent(e.target.dataset.post))
   .then(r=>r.json()).then(d=>e.target.textContent=d.state?'❤️':'🤍');
 }
});
</script>
</body>
</html>
