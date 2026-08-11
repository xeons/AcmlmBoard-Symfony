<?php
  Header('Content-type:image/png');
  $img=ImageCreate(25,15);
  $ranks=ImageCreateFromPNG('ranks.png');
  ImageCopy($img,$ranks,0,0,0,$_GET[num]*15,25,15);
  ImagePNG($img);
?>