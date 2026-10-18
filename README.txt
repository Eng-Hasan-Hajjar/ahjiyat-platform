E32 - Sponsorship checkout, payment operations, contribution requests and financial reconciliation
NO GIT COMMANDS. Baseline: v31 = 151824b. This archive lives OUTSIDE the repository on purpose; do not copy the archive, this README or the manifest into the project.

Contents
  new-files/            58 new files (including 1 migration), at their repository paths
  modified-full-files/  13 modified files, FULL final content, at their repository paths
  manifest-sha256.txt   '<sha256>  <repo-path>' for all 71 files

0 Composer packages / 0 npm packages. composer.json, composer.lock and package*.json are untouched. No frontend asset changed (no npm run build).

PowerShell (from the project root):
  $pack = "$env:TEMP\e32"
  Expand-Archive e32-files.zip -DestinationPath $pack -Force
  Copy-Item "$pack\new-files\*" . -Recurse -Force
  Copy-Item "$pack\modified-full-files\*" . -Recurse -Force
  Get-Content "$pack\manifest-sha256.txt" | ForEach-Object {
      $h, $p = $_ -split '\s+', 2
      $a = (Get-FileHash -Algorithm SHA256 -LiteralPath $p).Hash.ToLower()
      if ($a -eq $h) { "OK   $p" } else { "DIFF $p" }
  }

Then follow E32-delivery-pack-A-F.md (sections D, E, F). E32 is NOT closed until the MySQL items marked
USER-LOCAL MYSQL VALIDATION REQUIRED have been run on your machine and their output sent back.
