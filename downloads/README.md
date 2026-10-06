# downloads/

`BEC-Report.apk` is the Android app offered by **Get the app** (`includes/install_app.php`)
to reporters on Android phones.

It is a **Trusted Web Activity**: a real, installable Android app (package `com.becpmo.report`,
launcher name *BEC Report*) that opens `https://becpmo.com/student_index.php` full screen in the
phone's Chrome engine. The app holds no copy of the system — every change to the website shows up
in it immediately, so the APK itself only needs rebuilding when its name, icon or start page
change.

Android only shows the app without a browser address bar once it has verified that the site and
the app belong together. That proof is `/.well-known/assetlinks.json`, which lists the SHA-256
fingerprint of the key the APK is signed with. **The two must always match.**

## The signing key is not in this repository

It lives on the build laptop, outside git and outside OneDrive:

```
C:\Users\Jhan\bec-android\keys\bec-report.keystore   (alias: bec-report)
C:\Users\Jhan\bec-android\keys\.storepass             (its password)
```

Keep a backup of both. A lost key cannot be recovered: a new key means a new fingerprint in
`assetlinks.json`, and phones that installed the old APK must uninstall it before installing the
new one.

## Rebuilding

Built with [Bubblewrap](https://github.com/GoogleChromeLabs/bubblewrap) 1.25.0 from
`/manifest-reporter.webmanifest`. The toolchain (JDK 17, Android SDK, Bubblewrap) is in
`C:\Users\Jhan\bec-android\`.

1. `C:\Users\Jhan\bec-android\tools> node gen.js` — regenerates the Android project from the live
   manifest. Raise the version for every new APK: set `APP_VERSION_CODE` (a whole number, higher
   than the last) and `APP_VERSION_NAME` first.
2. In `C:\Users\Jhan\bec-android\bec-report`: `gradlew.bat assembleRelease`, then align and sign
   `app\build\outputs\apk\release\app-release-unsigned.apk` with `zipalign` and `apksigner` from
   the SDK's `build-tools\36.1.0`, using the key above.
3. Copy the signed APK here as `BEC-Report.apk`, commit, deploy.

| Version | Code | Date       | Notes |
|---------|------|------------|-------|
| 1.0.0   | 1    | 2026-10-06 | First release: opens the reporter sign-in; Report, Track and Public reports shortcuts |
