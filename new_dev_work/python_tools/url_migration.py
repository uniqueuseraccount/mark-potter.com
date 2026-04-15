#!/usr/bin/env python3
"""
WordPress URL Migration Script
Converts full URLs to relative URLs for Next.js image optimization

Usage:
    python url_migration.py --dry-run    # Preview changes
    python url_migration.py --execute    # Apply changes
"""

import pymysql
import argparse
import re
from datetime import datetime
from typing import Dict, List, Tuple
from secrets.dbsecrets import DB_CONFIG # protect db secrets

# URL patterns to replace
URL_PATTERNS = [
    ('http://www.mark-potter.com/wp-content/uploads/', '/wp-content/uploads/'),
    ('http://mark-potter.com/wp-content/uploads/', '/wp-content/uploads/'),
    ('https://www.mark-potter.com/wp-content/uploads/', '/wp-content/uploads/'),
    ('https://mark-potter.com/wp-content/uploads/', '/wp-content/uploads/'),
]

def get_connection():
    """Create database connection"""
    try:
        return pymysql.connect(**DB_CONFIG)
    except Exception as e:
        print(f"Error connecting to database: {e}")
        raise

def analyze_urls(conn) -> Dict[str, int]:
    """Analyze how many URLs will be affected"""
    cursor = conn.cursor()
    results = {}

    # Check wp_posts.post_content
    for old_url, _ in URL_PATTERNS:
        cursor.execute("""
            SELECT COUNT(*) FROM wp_posts
            WHERE post_content LIKE %s
        """, (f'%{old_url}%',))
        count = cursor.fetchone()[0]
        if count > 0:
            results[f'wp_posts.post_content ({old_url})'] = count

    # Check wp_postmeta.meta_value
    for old_url, _ in URL_PATTERNS:
        cursor.execute("""
            SELECT COUNT(*) FROM wp_postmeta
            WHERE meta_value LIKE %s
        """, (f'%{old_url}%',))
        count = cursor.fetchone()[0]
        if count > 0:
            results[f'wp_postmeta.meta_value ({old_url})'] = count

    # Check for NextGEN Gallery tables (if they exist)
    cursor.execute("SHOW TABLES LIKE 'wp_ngg_%'")
    ngg_tables = cursor.fetchall()

    for table in ngg_tables:
        table_name = table[0]
        # Check if table has likely URL columns
        cursor.execute(f"SHOW COLUMNS FROM {table_name}")
        columns = cursor.fetchall()

        for column in columns:
            col_name = column[0]
            col_type = column[1]

            # Only check text-based columns
            if 'text' in col_type.lower() or 'varchar' in col_type.lower():
                for old_url, _ in URL_PATTERNS:
                    cursor.execute(f"""
                        SELECT COUNT(*) FROM {table_name}
                        WHERE {col_name} LIKE %s
                    """, (f'%{old_url}%',))
                    count = cursor.fetchone()[0]
                    if count > 0:
                        results[f'{table_name}.{col_name} ({old_url})'] = count

    cursor.close()
    return results

def migrate_wp_posts(conn, dry_run=True) -> int:
    """Migrate URLs in wp_posts.post_content and guid"""
    cursor = conn.cursor()
    total_updated = 0

    for old_url, new_url in URL_PATTERNS:
        # Migrate post_content
        if dry_run:
            cursor.execute("""
                SELECT ID, post_title, post_content
                FROM wp_posts
                WHERE post_content LIKE %s
                LIMIT 5
            """, (f'%{old_url}%',))

            samples = cursor.fetchall()
            if samples:
                print(f"\n  Sample posts affected by {old_url}:")
                for post_id, title, content in samples:
                    print(f"    - ID {post_id}: {title[:50]}")
        else:
            cursor.execute("""
                UPDATE wp_posts
                SET post_content = REPLACE(post_content, %s, %s)
                WHERE post_content LIKE %s
            """, (old_url, new_url, f'%{old_url}%'))

            updated = cursor.rowcount
            total_updated += updated
            if updated > 0:
                print(f"  Updated {updated} post_content rows ({old_url} → {new_url})")

        # Migrate guid for attachments
        if dry_run:
            cursor.execute("""
                SELECT ID, post_title, guid
                FROM wp_posts
                WHERE post_type = 'attachment' AND guid LIKE %s
                LIMIT 5
            """, (f'%{old_url}%',))

            samples = cursor.fetchall()
            if samples:
                print(f"\n  Sample attachments affected by {old_url}:")
                for post_id, title, guid in samples:
                    print(f"    - ID {post_id}: {guid}")
        else:
            cursor.execute("""
                UPDATE wp_posts
                SET guid = REPLACE(guid, %s, %s)
                WHERE post_type = 'attachment' AND guid LIKE %s
            """, (old_url, new_url, f'%{old_url}%'))

            updated = cursor.rowcount
            total_updated += updated
            if updated > 0:
                print(f"  Updated {updated} attachment guid rows ({old_url} → {new_url})")

    cursor.close()
    return total_updated

def migrate_wp_postmeta(conn, dry_run=True) -> int:
    """Migrate URLs in wp_postmeta.meta_value"""
    cursor = conn.cursor()
    total_updated = 0

    for old_url, new_url in URL_PATTERNS:
        if dry_run:
            cursor.execute("""
                SELECT post_id, meta_key, meta_value
                FROM wp_postmeta
                WHERE meta_value LIKE %s
                LIMIT 5
            """, (f'%{old_url}%',))

            samples = cursor.fetchall()
            if samples:
                print(f"\n  Sample postmeta affected by {old_url}:")
                for post_id, meta_key, meta_value in samples:
                    print(f"    - Post {post_id}, Key: {meta_key}")
        else:
            cursor.execute("""
                UPDATE wp_postmeta
                SET meta_value = REPLACE(meta_value, %s, %s)
                WHERE meta_value LIKE %s
            """, (old_url, new_url, f'%{old_url}%'))

            updated = cursor.rowcount
            total_updated += updated
            if updated > 0:
                print(f"  Updated {updated} postmeta rows ({old_url} → {new_url})")

    cursor.close()
    return total_updated

def migrate_ngg_tables(conn, dry_run=True) -> int:
    """Migrate URLs in NextGEN Gallery tables"""
    cursor = conn.cursor()
    total_updated = 0

    # Find all NGG tables
    cursor.execute("SHOW TABLES LIKE 'wp_ngg_%'")
    ngg_tables = cursor.fetchall()

    if not ngg_tables:
        print("  No NextGEN Gallery tables found")
        return 0

    for table in ngg_tables:
        table_name = table[0]

        # Get columns
        cursor.execute(f"SHOW COLUMNS FROM {table_name}")
        columns = cursor.fetchall()

        for column in columns:
            col_name = column[0]
            col_type = column[1]

            # Only check text-based columns
            if 'text' in col_type.lower() or 'varchar' in col_type.lower():
                for old_url, new_url in URL_PATTERNS:
                    if dry_run:
                        cursor.execute(f"""
                            SELECT * FROM {table_name}
                            WHERE {col_name} LIKE %s
                            LIMIT 3
                        """, (f'%{old_url}%',))

                        samples = cursor.fetchall()
                        if samples:
                            print(f"\n  Sample rows from {table_name}.{col_name}:")
                            for row in samples:
                                print(f"    - {row[:3]}")
                    else:
                        cursor.execute(f"""
                            UPDATE {table_name}
                            SET {col_name} = REPLACE({col_name}, %s, %s)
                            WHERE {col_name} LIKE %s
                        """, (old_url, new_url, f'%{old_url}%'))

                        updated = cursor.rowcount
                        total_updated += updated
                        if updated > 0:
                            print(f"  Updated {updated} rows in {table_name}.{col_name}")

    cursor.close()
    return total_updated

def generate_report(analysis: Dict[str, int], dry_run: bool) -> str:
    """Generate migration report"""
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')

    report = f"""
WordPress URL Migration Report
{'=' * 60}
Generated: {timestamp}
Mode: {'DRY RUN (no changes made)' if dry_run else 'EXECUTION (changes applied)'}

Analysis Results:
{'-' * 60}
"""

    if not analysis:
        report += "No URLs found matching migration patterns.\n"
    else:
        total = sum(analysis.values())
        report += f"Total affected rows: {total}\n\n"

        for location, count in sorted(analysis.items()):
            report += f"  {location}: {count} rows\n"

    report += f"\n{'=' * 60}\n"

    return report

def main():
    parser = argparse.ArgumentParser(description='WordPress URL Migration Script')
    parser.add_argument('--dry-run', action='store_true',
                       help='Preview changes without applying them')
    parser.add_argument('--execute', action='store_true',
                       help='Execute the migration')

    args = parser.parse_args()

    if not args.dry_run and not args.execute:
        parser.error("Must specify either --dry-run or --execute")

    dry_run = args.dry_run

    print(f"\n{'DRY RUN MODE' if dry_run else 'EXECUTION MODE'}")
    print("=" * 60)

    try:
        # Connect to database
        print("\nConnecting to database...")
        conn = get_connection()
        print("✓ Connected")

        # Analyze URLs
        print("\nAnalyzing URLs...")
        analysis = analyze_urls(conn)

        # Generate and display report
        report = generate_report(analysis, dry_run)
        print(report)

        if not analysis:
            print("No migration needed. Exiting.")
            return

        # Confirm execution
        if not dry_run:
            print("\n⚠️  WARNING: You are about to modify the database!")
            confirm = input("Type 'YES' to continue: ")
            if confirm != 'YES':
                print("Migration cancelled.")
                return

        # Perform migration
        print(f"\n{'Previewing' if dry_run else 'Executing'} migration...")
        print("-" * 60)

        print("\n1. Migrating wp_posts.post_content...")
        posts_updated = migrate_wp_posts(conn, dry_run)

        print("\n2. Migrating wp_postmeta.meta_value...")
        postmeta_updated = migrate_wp_postmeta(conn, dry_run)

        print("\n3. Checking NextGEN Gallery tables...")
        ngg_updated = migrate_ngg_tables(conn, dry_run)

        # Commit changes
        if not dry_run:
            conn.commit()
            print("\n✓ Changes committed to database")

            total_updated = posts_updated + postmeta_updated + ngg_updated
            print(f"\nTotal rows updated: {total_updated}")
        else:
            print("\n✓ Dry run complete (no changes made)")

        # Save report
        report_filename = f"/home/claude/scripts/url_migration_report_{datetime.now().strftime('%Y%m%d_%H%M%S')}.txt"
        with open(report_filename, 'w') as f:
            f.write(report)
        print(f"\nReport saved to: {report_filename}")

        conn.close()

    except Exception as e:
        print(f"\n❌ Error: {e}")
        if 'conn' in locals():
            conn.rollback()
            conn.close()
        raise

if __name__ == '__main__':
    main()
