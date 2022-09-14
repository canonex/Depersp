#!/bin/bash

#Check if command installed
command -v magick >/dev/null 2>&1 || { echo "$(tput setaf 1)Command magick not found. Exiting. $(tput sgr 0)"; exit 1; }


filename=$(basename -- "$1")
name="${filename%.*}"

#https://www.imagemagick.org/Usage/distorts/#perspective
magick "$1" -colorspace RGB -distort Perspective "$2 $3 $4 $5 $6 $7 $8 $9" -crop 1024x1024+10+10 -colorspace sRGB "$1-dp.png"

echo "$1-dp.png"
#rm "$1"

