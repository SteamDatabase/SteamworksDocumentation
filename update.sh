#!/bin/bash

cd "$(dirname "$0")"

php crawl.php
yarn format-docs

git add -A
git commit -S -a -m "Update documentation"
git push
