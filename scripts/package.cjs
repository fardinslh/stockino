const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execSync } = require('child_process');

const rootDir = path.resolve(__dirname, '..');
const distDir = path.join(rootDir, 'dist');
const zipPath = path.join(distDir, 'stockino-1.0.0.zip');
const stagingRoot = path.join(distDir, 'staging');
const stagingPluginDir = path.join(stagingRoot, 'stockino');

// Clean previous staging & zip
if (fs.existsSync(stagingRoot)) fs.rmSync(stagingRoot, { recursive: true, force: true });
if (fs.existsSync(zipPath)) fs.unlinkSync(zipPath);

fs.mkdirSync(stagingPluginDir, { recursive: true });

// Files to copy directly
const filesToCopy = ['stockino.php', 'uninstall.php', 'README.md', 'CHANGELOG.md', 'RELEASE_NOTES.md', 'composer.json'];
for (const file of filesToCopy) {
  const src = path.join(rootDir, file);
  if (fs.existsSync(src)) {
    fs.copyFileSync(src, path.join(stagingPluginDir, file));
  }
}

// Copy directories
function copyDir(src, dest) {
  fs.mkdirSync(dest, { recursive: true });
  const entries = fs.readdirSync(src, { withFileTypes: true });
  for (const entry of entries) {
    const srcPath = path.join(src, entry.name);
    const destPath = path.join(dest, entry.name);
    if (entry.isDirectory()) {
      copyDir(srcPath, destPath);
    } else {
      fs.copyFileSync(srcPath, destPath);
    }
  }
}

copyDir(path.join(rootDir, 'src'), path.join(stagingPluginDir, 'src'));
copyDir(path.join(rootDir, 'dist', 'assets'), path.join(stagingPluginDir, 'dist', 'assets'));

const viteManifestSrc = path.join(rootDir, 'dist', '.vite', 'manifest.json');
if (fs.existsSync(viteManifestSrc)) {
  const destVite = path.join(stagingPluginDir, 'dist', '.vite');
  fs.mkdirSync(destVite, { recursive: true });
  fs.copyFileSync(viteManifestSrc, path.join(destVite, 'manifest.json'));
}

if (fs.existsSync(path.join(rootDir, 'languages'))) {
  copyDir(path.join(rootDir, 'languages'), path.join(stagingPluginDir, 'languages'));
}

// Compress using PowerShell Compress-Archive
console.log('Compressing archive...');
execSync(`powershell -Command "Compress-Archive -Path '${stagingRoot}\\stockino' -DestinationPath '${zipPath}' -Force"`, { stdio: 'inherit' });

// Clean staging
fs.rmSync(stagingRoot, { recursive: true, force: true });

// Verify & compute SHA-256
const fileBuffer = fs.readFileSync(zipPath);
const hashSum = crypto.createHash('sha256').update(fileBuffer).digest('hex');
const stats = fs.statSync(zipPath);

// Count entries in zip
const entryCountOutput = execSync(
  `powershell -Command "Add-Type -AssemblyName System.IO.Compression.FileSystem; [System.IO.Compression.ZipFile]::OpenRead('${zipPath}').Entries.Count"`,
  { encoding: 'utf8' }
).trim();

console.log('====================================');
console.log('Stockino 1.0.0 Packaging Complete');
console.log(`Archive: ${zipPath}`);
console.log(`Size: ${stats.size} bytes (${(stats.size / 1024).toFixed(2)} KB)`);
console.log(`Entries: ${entryCountOutput}`);
console.log(`SHA-256: ${hashSum}`);
console.log('====================================');
