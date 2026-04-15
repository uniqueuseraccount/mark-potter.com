#!/usr/bin/env python3
"""
WordPress Content Audit Tool
Generates CSV report of all posts with metadata for migration planning

Usage:
    python content_audit.py
"""

import pymysql
import csv
import re
from datetime import datetime
from typing import Dict, List, Tuple
from secrets.dbsecrets import DB_CONFIG # protect db secrets


def get_connection():
    """Create database connection"""
    try:
        return pymysql.connect(**DB_CONFIG)
    except Exception as e:
        print(f"Error connecting to database: {e}")
        raise

def has_gallery_shortcode(content: str) -> bool:
    """Check if post has [gallery] shortcode"""
    return bool(re.search(r'\[gallery[^\]]*\]', content))

def has_gallery_block(content: str) -> bool:
    """Check if post has gallery block"""
    return 'wp:gallery' in content or 'wp-block-gallery' in content

def count_images_in_content(content: str) -> int:
    """Count images in post content (not in galleries)"""
    # Count wp:image blocks
    wp_images = len(re.findall(r'<!-- wp:image', content))

    # Count img tags not in galleries
    img_tags = len(re.findall(r'<img[^>]*>', content))

    return max(wp_images, img_tags)

def has_video(content: str) -> bool:
    """Check if post has embedded video"""
    patterns = [
        r'<!-- wp:video',
        r'<!-- wp:embed',
        r'<iframe[^>]*youtube',
        r'<iframe[^>]*vimeo',
        r'\[video[^\]]*\]',
        r'\[embed[^\]]*\]'
    ]
    return any(re.search(pattern, content, re.IGNORECASE) for pattern in patterns)

def get_featured_image_url(conn, post_id: int) -> str:
    """Get featured image URL for a post"""
    cursor = conn.cursor()

    # Get thumbnail ID from postmeta
    cursor.execute("""
        SELECT meta_value
        FROM wp_postmeta
        WHERE post_id = %s AND meta_key = '_thumbnail_id'
    """, (post_id,))

    result = cursor.fetchone()
    if not result:
        cursor.close()
        return ''

    thumbnail_id = result[0]

    # Get the image URL
    cursor.execute("""
        SELECT guid
        FROM wp_posts
        WHERE ID = %s
    """, (thumbnail_id,))

    result = cursor.fetchone()
    cursor.close()

    return result[0] if result else ''

def get_tags(conn, post_id: int) -> str:
    """Get comma-separated list of tags for a post"""
    cursor = conn.cursor()

    cursor.execute("""
        SELECT t.name
        FROM wp_terms t
        INNER JOIN wp_term_taxonomy tt ON t.term_id = tt.term_id
        INNER JOIN wp_term_relationships tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
        WHERE tr.object_id = %s AND tt.taxonomy = 'post_tag'
        ORDER BY t.name
    """, (post_id,))

    tags = [row[0] for row in cursor.fetchall()]
    cursor.close()

    return ', '.join(tags)

def get_categories(conn, post_id: int) -> str:
    """Get comma-separated list of categories for a post"""
    cursor = conn.cursor()

    cursor.execute("""
        SELECT t.name
        FROM wp_terms t
        INNER JOIN wp_term_taxonomy tt ON t.term_id = tt.term_id
        INNER JOIN wp_term_relationships tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
        WHERE tr.object_id = %s AND tt.taxonomy = 'category'
        ORDER BY t.name
    """, (post_id,))

    categories = [row[0] for row in cursor.fetchall()]
    cursor.close()

    return ', '.join(categories)

def get_word_count(content: str) -> int:
    """Get approximate word count of post content"""
    # Remove HTML tags
    text = re.sub(r'<[^>]+>', ' ', content)
    # Remove shortcodes
    text = re.sub(r'\[[^\]]+\]', ' ', text)
    # Remove block comments
    text = re.sub(r'<!--[^>]+-->', ' ', text)
    # Count words
    words = text.split()
    return len(words)

def audit_posts(conn) -> List[Dict]:
    """Audit all published posts"""
    cursor = conn.cursor(pymysql.cursors.DictCursor)

    # Get all published posts
    cursor.execute("""
        SELECT
            ID,
            post_title,
            post_date,
            post_content,
            post_type,
            post_status
        FROM wp_posts
        WHERE post_status = 'publish'
        AND post_type IN ('post', 'page')
        ORDER BY post_date DESC
    """)

    posts = []
    total = cursor.rowcount
    print(f"\nAuditing {total} posts...")

    for i, row in enumerate(cursor.fetchall(), 1):
        post_id = row['ID']
        content = row['post_content'] or ''

        # Analyze content
        has_gallery = has_gallery_shortcode(content) or has_gallery_block(content)
        standalone_images = count_images_in_content(content)
        has_vid = has_video(content)
        featured_img = get_featured_image_url(conn, post_id)
        tags = get_tags(conn, post_id)
        categories = get_categories(conn, post_id)
        word_count = get_word_count(content)

        posts.append({
            'post_id': post_id,
            'post_type': row['post_type'],
            'title': row['post_title'],
            'date': row['post_date'].strftime('%Y-%m-%d'),
            'has_featured_image': 'Y' if featured_img else 'N',
            'featured_image_url': featured_img,
            'has_gallery': 'Y' if has_gallery else 'N',
            'standalone_images': standalone_images,
            'has_video': 'Y' if has_vid else 'N',
            'word_count': word_count,
            'tags': tags,
            'categories': categories
        })

        if i % 10 == 0:
            print(f"  Processed {i}/{total} posts...")

    cursor.close()
    return posts

def generate_summary(posts: List[Dict]) -> str:
    """Generate summary statistics"""
    total = len(posts)

    with_featured = sum(1 for p in posts if p['has_featured_image'] == 'Y')
    without_featured = total - with_featured

    with_gallery = sum(1 for p in posts if p['has_gallery'] == 'Y')
    with_standalone = sum(1 for p in posts if p['standalone_images'] > 0)
    with_video = sum(1 for p in posts if p['has_video'] == 'Y')

    text_only = sum(1 for p in posts
                    if p['has_gallery'] == 'N'
                    and p['standalone_images'] == 0
                    and p['has_video'] == 'N')

    summary = f"""
Content Audit Summary
{'=' * 60}

Total Posts: {total}

Featured Images:
  With featured image: {with_featured} ({with_featured/total*100:.1f}%)
  Without featured image: {without_featured} ({without_featured/total*100:.1f}%)

Content Types:
  Posts with galleries: {with_gallery} ({with_gallery/total*100:.1f}%)
  Posts with standalone images: {with_standalone} ({with_standalone/total*100:.1f}%)
  Posts with videos: {with_video} ({with_video/total*100:.1f}%)
  Text-only posts: {text_only} ({text_only/total*100:.1f}%)

Post Types:
"""

    post_types = {}
    for p in posts:
        post_types[p['post_type']] = post_types.get(p['post_type'], 0) + 1

    for post_type, count in sorted(post_types.items()):
        summary += f"  {post_type}: {count}\n"

    summary += f"\n{'=' * 60}\n"

    return summary

def main():
    print("\nWordPress Content Audit Tool")
    print("=" * 60)

    try:
        # Connect to database
        print("\nConnecting to database...")
        conn = get_connection()
        print("✓ Connected")

        # Audit posts
        posts = audit_posts(conn)

        # Generate CSV
        timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
        csv_filename = f'/scripts/content_audit_{timestamp}.csv'

        print(f"\nWriting CSV report...")
        with open(csv_filename, 'w', newline='', encoding='utf-8') as csvfile:
            fieldnames = [
                'post_id', 'post_type', 'title', 'date',
                'has_featured_image', 'featured_image_url',
                'has_gallery', 'standalone_images', 'has_video',
                'word_count', 'tags', 'categories'
            ]

            writer = csv.DictWriter(csvfile, fieldnames=fieldnames)
            writer.writeheader()
            writer.writerows(posts)

        print(f"✓ CSV saved to: {csv_filename}")

        # Generate summary
        summary = generate_summary(posts)
        print(summary)

        # Save summary
        summary_filename = f'/scripts/content_audit_summary_{timestamp}.txt'
        with open(summary_filename, 'w') as f:
            f.write(summary)

        print(f"Summary saved to: {summary_filename}")

        conn.close()

    except Exception as e:
        print(f"\n❌ Error: {e}")
        raise

if __name__ == '__main__':
    main()
