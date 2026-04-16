# scripts/check-css.sh
bash scripts/build-css.sh
git diff --exit-code public/assets/css/app.css || {
  echo "❌ app.css is out of sync with LESS. Run build-css.sh and commit."
  exit 1
}
