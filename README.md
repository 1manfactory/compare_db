# compare_db

`compare_db` is a command-line PHP tool that compares the database schema definitions between your development and production MySQL/MariaDB environments. It loads environment variables from a `.env` file, establishes database connections using PDO, retrieves the `CREATE` statements for tables, views, procedures, and functions, and highlights any differences in the terminal output.

## Features

- Loads environment variables from a `.env` file.
- Connects to MySQL/MariaDB databases via PDO.
- Retrieves `CREATE` statements for:
  - Tables (excluding those with `_mv` or `_pv` suffixes)
  - Views
  - Stored Procedures
  - Functions
- Compares schema definitions and outputs color-coded differences in the console.
- Executes additional dump scripts if discrepancies are found.

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
DEV_DB_NAME=your_dev_database

# Production Database
PROD_DB_HOST=production_host
PROD_DB_USER=your_prod_user
PROD_DB_PASS=your_prod_password
PROD_DB_NAME=your_prod_database
```

## Usage

Run the script from the command line:

```bash
./compare_db
```

The script will:
- Load the environment variables.
- Establish connections to both the development and production databases.
- Retrieve and compare the `CREATE` definitions for the specified database objects.
- Output any differences using color-coded messages in the terminal.

If differences are detected, the script will also execute additional dump scripts (this behavior can be customized in the code).

## Troubleshooting

- **.env file issues:** Ensure your `.env` file is in the same directory as the script and correctly configured.
- **Database connection errors:** Verify that your credentials are correct and that both databases are accessible.
- **Permissions:** Confirm that the script has executable permissions.

## Contributing

Contributions and improvements are welcome! Please open an issue or submit a pull request if you have any suggestions or bug fixes. Thx.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.
