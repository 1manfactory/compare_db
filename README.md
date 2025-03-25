# compare_db

`compare_db` is a command-line PHP tool that compares the database schema definitions between your development and production MySQL/MariaDB environments. The tool dynamically compares all non-system databases between both environments. It loads environment variables from a `.env` file, establishes database connections using PDO, retrieves the `CREATE` statements for tables, views, procedures, and functions from each database, and highlights any differences in the terminal output.

## Features

- Loads environment variables from a `.env` file.
- Connects to MySQL/MariaDB databases via PDO.
- Dynamically retrieves a list of non-system databases (excluding `mysql`, `information_schema`, `performance_schema`, and `sys`).
- Retrieves `CREATE` statements for:
  - Tables (excluding those with `_mv` or `_pv` suffixes)
  - Views
  - Stored Procedures
  - Functions
- Compares schema definitions across all common databases between development and production.
- Outputs color-coded differences in the console.
- Optionally executes additional dump scripts if discrepancies are found (customizable in the code).

## Requirements

- PHP 7.4 or higher.
- MySQL/MariaDB databases.
- (Optional) Composer if you choose to manage dependencies or autoloading.

## Installation

1. **Clone the repository:**

   ```bash
   git clone https://your.repository.url.git
   cd your-repository-folder
   ```

2. **Make the script executable:**

   ```bash
   chmod +x compare_db
   ```

3. **Create your `.env` file:**

   Copy the `.env.example` file to `.env` and update the database credentials accordingly.

## .env Configuration

Your `.env` file should contain the necessary settings for both development and production environments. For example:

```dotenv
# Development Database
DEV_DB_HOST=localhost
DEV_DB_USER=your_dev_user
DEV_DB_PASS=your_dev_password

# Production Database
PROD_DB_HOST=production_host
PROD_DB_USER=your_prod_user
PROD_DB_PASS=your_prod_password
```

> **Note:** The script automatically detects all non-system databases on both environments. Only databases common to both environments will be compared.

## Usage

Run the script from the command line:

```bash
./compare_db
```

The script will:
- Load the environment variables.
- Establish connections to both the development and production database servers.
- Retrieve a list of all non-system databases from both environments.
- For each database common to both environments, retrieve and compare the `CREATE` definitions for tables, views, procedures, and functions.
- Output any differences using color-coded messages in the terminal.
- Optionally execute additional dump scripts if discrepancies are detected (this behavior can be customized in the code).

## Troubleshooting

- **.env file issues:** Ensure your `.env` file is in the same directory as the script and correctly configured.
- **Database connection errors:** Verify that your credentials are correct and that both database servers are accessible.
- **Permissions:** Confirm that the script has executable permissions.

## Contributing

Contributions and improvements are welcome! Please open an issue or submit a pull request if you have any suggestions or bug fixes.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.
