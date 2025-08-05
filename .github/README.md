# GitHub Workflows

This directory contains GitHub Actions workflows for automated testing and validation of the local_cleanup plugin.

## Available Workflows

### CI Workflow (`ci.yml`)
**Purpose**: Validates the plugin against Moodle coding standards and ensures functionality across different PHP/Moodle versions.

**Triggers**:
- Push to main branches (`master`, `main`, `develop`)
- Pull requests to main branches

**What it tests**:
- PHP syntax validation
- Moodle coding standards compliance
- PHPDoc documentation standards
- Plugin structure validation
- Database upgrade validation
- CLI scripts functionality

### Release Workflow (`release.yml`)
**Purpose**: Automates the release process when new versions are tagged.

**Triggers**:
- Git tags starting with `v*`
- GitHub releases

**What it does**:
- Validates version consistency
- Creates distribution packages
- Publishes GitHub releases
- Generates checksums

## Status Badges

Add these badges to your main README.md:

```markdown
[![Moodle Plugin CI](https://github.com/grinchenkoedu/local_cleanup/workflows/Moodle%20Plugin%20CI/badge.svg)](https://github.com/grinchenkoedu/local_cleanup/actions)
[![Release](https://github.com/grinchenkoedu/local_cleanup/workflows/Release/badge.svg)](https://github.com/grinchenkoedu/local_cleanup/actions)
```

## Creating a Release

1. Update `version.php` with new version number and release string
2. Update `CHANGELOG.md` with release notes
3. Create and push a git tag:
   ```bash
   git tag v2.2
   git push origin v2.2
   ```

The release workflow will automatically handle the rest.

## Local Development

To run similar checks locally:

```bash
# Install moodle-plugin-ci
composer create-project -n --no-dev --prefer-dist moodlehq/moodle-plugin-ci ci ^4

# Run code checker
ci/bin/moodle-plugin-ci codechecker

# Run PHPDoc checker  
ci/bin/moodle-plugin-ci phpdoc
```

## Benefits

- ✅ Automated quality assurance
- ✅ Moodle standards compliance
- ✅ Multi-version compatibility testing
- ✅ Streamlined release process
- ✅ Continuous integration feedback

## Resources

- [Moodle Plugin CI Documentation](https://moodlehq.github.io/moodle-plugin-ci/)
- [Moodle Development Docs](https://docs.moodle.org/dev/)
- [Moodle Coding Standards](https://docs.moodle.org/dev/Coding_style)
