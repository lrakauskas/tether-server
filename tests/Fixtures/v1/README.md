# Released compatibility fixtures

These files are unmodified runtime source, configuration, migrations, and Composer
metadata exported with `git archive v1.0.0` from `tether/core` and `tether/client`.
Both packages are MIT licensed as declared in their captured manifests.
`provenance.json` records the exact release commits and source tree hashes.
The core source trees for v0.0.1 and v1.0.0 are identical.

Do not update these fixtures when changing current package code. They intentionally
preserve the old integer DTOs and client pull loop. The separate compatibility
bootstrap loads these classes before the installed core/client namespaces:

```sh
php vendor/bin/pest --configuration phpunit-compatibility.xml
```

No network, Git checkout, or sibling package checkout is needed to run this test.
