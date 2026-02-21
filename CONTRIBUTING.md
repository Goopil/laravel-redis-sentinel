# Contributing to Laravel Redis Sentinel

Thank you for considering contributing to Laravel Redis Sentinel! This document outlines the process for contributing to this project.

## Code of Conduct

- Be respectful and inclusive
- Focus on constructive feedback
- Help others learn and grow

## How to Contribute

### Reporting Bugs

Before creating a bug report:
- Check existing issues to avoid duplicates
- Test with the latest version of the package
- Verify the bug is related to this package, not Laravel or Redis itself

When reporting a bug, include:
- PHP version, Laravel version, Redis version
- Package version
- Clear steps to reproduce
- Expected vs actual behavior
- Relevant error messages and stack traces
- Redis Sentinel configuration (sentinels, master name, etc.)

### Suggesting Features

Feature requests are welcome! Please:
- Check if the feature already exists
- Clearly describe the use case
- Explain how it benefits Redis Sentinel users
- Consider backward compatibility

### Pull Requests

1. **Fork and Clone**
   ```bash
   git clone https://github.com/yourusername/laravel-redis-sentinel.git
   cd laravel-redis-sentinel
   ```

2. **Install Dependencies**
   ```bash
   composer install
   ```

3. **Set Up Local Redis Sentinel Cluster**
   ```bash
   docker compose up -d
   ```

4. **Create a Feature Branch**
   ```bash
   git checkout -b feature/my-new-feature
   # or
   git checkout -b fix/issue-description
   ```

5. **Make Your Changes**
   - Follow PSR-12 coding standards
   - Add tests for new functionality
   - Update documentation if needed

6. **Run Tests**
   ```bash
   composer test
   ```

7. **Run Code Style Checks**
   ```bash
   composer lint
   ```

8. **Commit Your Changes**
   ```bash
   git add .
   git commit -m "Add feature: description"
   ```

   Use clear, descriptive commit messages:
   - `feat: add read timeout configuration`
   - `fix: prevent master client corruption in retry loop`
   - `docs: update Kubernetes deployment example`
   - `test: add coverage for failover scenarios`

9. **Push and Create PR**
   ```bash
   git push origin feature/my-new-feature
   ```

   Then create a pull request on GitHub with:
   - Clear description of changes
   - Link to related issues
   - Screenshots/examples if applicable

## Development Guidelines

### Code Style

- Follow PSR-12 coding standard
- Use type hints for all parameters and return types
- Add PHPDoc blocks for complex methods
- Run `composer lint` before committing

### Testing

- Write tests for all new features
- Ensure existing tests pass
- Aim for high test coverage
- Test both success and failure scenarios
- Include integration tests for Redis Sentinel interactions

Test structure:
```php
test('feature description', function () {
    // Arrange
    $connection = getRedisSentinelConnection();

    // Act
    $result = $connection->someMethod();

    // Assert
    expect($result)->toBeTrue();
});
```

### Adding New Features

When adding features:
1. Consider backward compatibility
2. Add configuration options where appropriate
3. Fire events for important state changes
4. Update README.md with usage examples
5. Add tests covering the feature
6. Document any breaking changes

### Testing Locally

The package includes a complete Redis Sentinel cluster via Docker Compose:

```bash
# Start the cluster
docker compose up -d

# Run tests
composer test

# Run specific test
vendor/bin/pest tests/Feature/MyTest.php

# Stop the cluster
docker compose down
```

The cluster includes:
- 1 Redis master (port 6380)
- 2 Redis replicas (ports 6381, 6382)
- 1 Redis Sentinel (port 26379)
- 1 Standalone Redis for comparison tests (port 6379)

### CI/CD

All pull requests run through GitHub Actions with:
- PHP versions: 8.2, 8.3, 8.4, 8.5
- Laravel versions: 10, 11, 12
- Redis versions: 6, 7

Tests run in parallel with isolated Redis clusters per job (342 tests total).

## Areas Needing Help

- Kubernetes deployment examples and best practices
- Performance benchmarks and optimizations
- Additional monitoring and health check integrations
- Support for additional Laravel features
- Documentation improvements
- Bug fixes and stability improvements

## Questions?

If you have questions:
- Open a GitHub issue for technical questions
- Check existing issues and pull requests
- Review the README.md and test files for examples

## License

By contributing, you agree that your contributions will be licensed under the LGPL-3.0 license.

Thank you for contributing! 🚀
