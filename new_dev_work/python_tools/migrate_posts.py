import mysql.connector
import re
import phpserialize
from secrets.dbsecrets import config #protect db secrets

# Note: In a real pod environment, DB_HOST would be 'wordpress-mysql'
# but since we are running this script FROM the pod or via port-forward,
# we need to ensure we can reach it.
# We will assume this script is run locally and port-forward is established.

def get_image_ids_from_content(content):
    """
    Parses post content to find image IDs.
    Looks for class="wp-image-123" which is standard WP behavior.
    """
    ids = []
    # Regex for wp-image-{id}
    matches = re.findall(r'wp-image-(\d+)', content)
    for match in matches:
        if match not in ids:
            ids.append(match)
    return ids

def migrate():
    try:
        cnx = mysql.connector.connect(**config)
        cursor = cnx.cursor(dictionary=True)

        # 1. Select all standard posts that are published
        query = "SELECT ID, post_title, post_content, post_name, post_author, post_date, post_date_gmt FROM wp_posts WHERE post_type = 'post' AND post_status = 'publish'"
        cursor.execute(query)
        posts = cursor.fetchall()

        print(f"Found {len(posts)} posts. Starting analysis...")

        for post in posts:
            image_ids = get_image_ids_from_content(post['post_content'])

            if len(image_ids) > 0:
                print(f"Post '{post['post_title']}' (ID: {post['ID']}) has {len(image_ids)} images. Converting to Gallery...")

                # 2. Create new Gallery Post
                # We append '-gallery' to the slug to avoid collision
                new_slug = post['post_name'] + "-gallery"

                # Updated INSERT query to include all fields required by strict SQL modes
                insert_post_query = """
                INSERT INTO wp_posts
                (post_author, post_date, post_date_gmt, post_content, post_title, post_status, comment_status, ping_status, post_name, post_type, post_modified, post_modified_gmt, post_excerpt, to_ping, pinged, post_content_filtered)
                VALUES
                (%s, %s, %s, '', %s, 'draft', 'closed', 'closed', %s, 'gallery', %s, %s, '', '', '', '')
                """

                cursor.execute(insert_post_query, (
                    post['post_author'],
                    post['post_date'],
                    post['post_date_gmt'],
                    post['post_title'],
                    new_slug,
                    post['post_date'],
                    post['post_date_gmt']
                ))

                new_gallery_id = cursor.lastrowid
                print(f" -> Created new Gallery ID: {new_gallery_id}")

                # 3. Add gallery_images Meta

                # Convert string IDs to integers
                int_ids = [int(i) for i in image_ids]

                # Save as serialized array to be compatible with standard WP/ACF storage
                serialized_images = phpserialize.dumps(int_ids)

                insert_meta_query = "INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (%s, %s, %s)"
                cursor.execute(insert_meta_query, (new_gallery_id, 'gallery_images', serialized_images))

                # Also add the featured image if the original post had one
                # Get _thumbnail_id from original post
                cursor.execute("SELECT meta_value FROM wp_postmeta WHERE post_id = %s AND meta_key = '_thumbnail_id'", (post['ID'],))
                thumb_result = cursor.fetchone()
                if thumb_result:
                     cursor.execute(insert_meta_query, (new_gallery_id, '_thumbnail_id', thumb_result['meta_value']))

                cnx.commit()
            else:
                print(f"Skipping Post '{post['post_title']}' - No images found in content.")

        cursor.close()
        cnx.close()
        print("Migration complete.")

    except mysql.connector.Error as err:
        print(f"Error: {err}")

if __name__ == "__main__":
    migrate()