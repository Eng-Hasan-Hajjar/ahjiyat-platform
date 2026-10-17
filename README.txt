E31 - MySQL production parity, SQLite->MySQL data transfer, database hardening
NO GIT COMMANDS. Baseline: v30 = 0b8cc5f. This archive lives OUTSIDE the repository on purpose; do not copy the archive, this README or the manifest into the project.

Contents
  new-files/            28 new files (including 2 migrations), at their repository paths
  modified-full-files/  21 modified files, FULL final content, at their repository paths
  manifest-sha256.txt   '<sha256>  <repo-path>' for all 49 files

0 Composer packages / 0 npm packages. composer.json, composer.lock and package*.json are untouched. No frontend asset changed (no npm run build).

PowerShell (from the project root):
  $pack = "$env:TEMP\e31"
  Expand-Archive e31-files.zip -DestinationPath $pack -Force
  Copy-Item "$pack\new-files\*" . -Recurse -Force
  Copy-Item "$pack\modified-full-files\*" . -Recurse -Force
  Get-Content "$pack\manifest-sha256.txt" | ForEach-Object {
      $h, $p = $_ -split '\s+', 2
      $a = (Get-FileHash -Algorithm SHA256 -LiteralPath $p).Hash.ToLower()
      if ($a -eq $h) { "OK   $p" } else { "DIFF $p" }
  }

Then follow E31-delivery-pack-A-G.md (sections E, F, G). E31 is NOT closed until the MySQL items marked
PENDING USER-LOCAL MYSQL VALIDATION have been run on your machine and their output sent back.
