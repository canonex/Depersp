#!/bin/bash

#echo "Deskew 2022"

#Check if command installed
command -v magick >/dev/null 2>&1 || { echo "$(tput setaf 1)Command magick not found. Exiting. $(tput sgr 0)"; exit 1; }


mogrify -auto-orient -resize "1024x1024^" "$1"

magick "$1" -color-threshold 'sRGB(0,0,0)-sRGB(70,70,70)' "$1_ma_soglia.png"



#Fill with alpha using color of pixel 0,0 with fuzz of x%
magick "$1_ma_soglia.png" -bordercolor "#$hex" -border 1x1 \
				-alpha set -channel RGBA -fuzz 5% \
				-fill none -floodfill +0+0 "#$hex"\
				-shave 10x10 "$1_mb_alpha.png"

convert "$1_mb_alpha.png" -bordercolor white -border 1 \
-fuzz 5% -fill "red" -draw "color 0,0 floodfill" -alpha off \
-shave 1x1 \
-fill white +opaque red -fill black -opaque red \
-edge 1 \
-fuzz 1% \
"$1_mb_border.png"


#Courtesy of https://github.com/ImageMagick/ImageMagick/discussions/5335
magick "$1_mb_border.png" -hough-lines 9x9+150 +write "$1_x4.png" "$1_x.mvg"

cat "$1_x.mvg"

rm "$1_ma_soglia.png"
rm "$1_mb_alpha.png"
rm "$1_mb_border.png"
rm "$1_x4.png"
rm "$1_x.mvg"

