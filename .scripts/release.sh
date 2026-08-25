#!/bin/bash
#
# Release script using conventional-changelog for version bumping.
#
# Usage: .scripts/release.sh [major|minor|patch|beta|rc]
#
# Examples:
#   .scripts/release.sh patch   # 1.0.0 -> 1.0.1
#   .scripts/release.sh minor   # 1.0.0 -> 1.1.0
#   .scripts/release.sh major   # 1.0.0 -> 2.0.0
#   .scripts/release.sh beta    # 1.0.0 -> 1.0.1-beta.1

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_ROOT"

# Check if git working directory is clean
if [[ -n $(git status -s) ]]; then
  echo "❌ Working directory not clean. Please commit or stash your changes."
  exit 1
fi

# Validate version bump type
BUMP_TYPE=${1:-patch}
if [[ ! $BUMP_TYPE =~ ^(major|minor|patch|beta|rc|alpha)$ ]]; then
  echo "❌ Invalid version bump type: $BUMP_TYPE"
  echo "Usage: $0 [major|minor|patch|beta|rc|alpha]"
  exit 1
fi

echo "🚀 Creating $BUMP_TYPE release..."
echo ""

# Generate changelog and create version tag
# --commit: Commit the changes
vendor/bin/conventional-changelog --$BUMP_TYPE --commit --history

# Get the new tag
NEW_TAG=$(git describe --tags --abbrev=0)

echo ""
echo "✅ Release $NEW_TAG created!"
echo ""
echo "📝 Recent changelog entries:"
echo ""
head -n 30 CHANGELOG.md

echo ""
echo "🔍 Review the changes:"
echo "  git show HEAD"
echo ""
echo "📤 Push to remote:"
echo "  git push origin master --tags"
echo ""
echo "📦 Publish to Packagist (auto-updates via webhook)"

# Check for Flex recipe
MAJOR_MINOR=$(echo "${NEW_TAG#v}" | cut -d. -f1,2)
RECIPE_DIR="recipes/$MAJOR_MINOR"

if [[ -d "$RECIPE_DIR" ]]; then
  echo ""
  echo "📋 Flex recipe found at $RECIPE_DIR"
  echo "   Submit PR to: https://github.com/symfony/recipes-contrib"
  echo "   Path: kachnitel/dynamic-form-bundle/$MAJOR_MINOR/"
else
  echo ""
  echo "📦 No Flex recipe found for version $MAJOR_MINOR"
  echo "   Consider creating: $RECIPE_DIR/{manifest.json,config/...}"
fi
