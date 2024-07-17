<?php
/**
 * Plugin Name: Typicode Todo
 * Description: Синхронизация задач с JSONPlaceholder API.
 * Version: 1.0
 * Author: Alexey Vorozhichshev
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Используем Psr\Log для логирования.
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class TodoSyncPlugin {
    private $api_url = 'https://jsonplaceholder.typicode.com/todos';
    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'todos';

        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_post_sync_todos', array($this, 'sync_todos'));
        add_shortcode('random_todos', array($this, 'display_random_todos'));
    }

    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            todo_id mediumint(9) NOT NULL,
            title text NOT NULL,
            completed boolean NOT NULL,
            PRIMARY KEY (id),
            UNIQUE (todo_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function deactivate() {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS $this->table_name");
    }

    public function add_admin_menu() {
        add_menu_page(
            'Todo Sync',
            'Todo Sync',
            'manage_options',
            'todo-sync',
            array($this, 'admin_page')
        );
    }

    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Todo Sync</h1>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="sync_todos">
                <?php submit_button('Sync Todos'); ?>
            </form>
            <h2>Поиск Todos</h2>
            <form method="get" action="">
                <input type="text" name="todo_search" value="<?php echo isset($_GET['todo_search']) ? esc_attr($_GET['todo_search']) : ''; ?>">
                <?php submit_button('Поиск', 'primary', 'search_todos', false); ?>
            </form>
            <?php
            if (isset($_GET['todo_search']) && !empty($_GET['todo_search'])) {
                $this->search_todos(sanitize_text_field($_GET['todo_search']));
            }
            ?>
        </div>
        <?php
    }

    public function sync_todos() {
        $response = wp_remote_get($this->api_url);

        if (is_wp_error($response)) {
            error_log('Error fetching todos: ' . $response->get_error_message(), 0);
            wp_die('Error fetching todos');
        }

        $todos = json_decode(wp_remote_retrieve_body($response), true);

        if (is_null($todos)) {
            error_log('Invalid JSON response', 0);
            wp_die('Invalid JSON response');
        }

        global $wpdb;
        foreach ($todos as $todo) {
            $wpdb->replace(
                $this->table_name,
                array(
                    'todo_id' => $todo['id'],
                    'title' => $todo['title'],
                    'completed' => $todo['completed'],
                ),
                array(
                    '%d',
                    '%s',
                    '%d'
                )
            );
        }

        wp_redirect(admin_url('admin.php?page=todo-sync&synced=true'));
        exit;
    }

    public function search_todos($search) {
        global $wpdb;
        $query = $wpdb->prepare("SELECT * FROM $this->table_name WHERE title LIKE %s", '%' . $wpdb->esc_like($search) . '%');
        $results = $wpdb->get_results($query);

        if (!empty($results)) {
            echo '<ul>';
            foreach ($results as $todo) {
                echo '<li>' . esc_html($todo->title) . '</li>';
            }
            echo '</ul>';
        } else {
            echo 'No todos found.';
        }
    }

    public function display_random_todos() {
        global $wpdb;
        $results = $wpdb->get_results("SELECT * FROM $this->table_name WHERE completed = 0 ORDER BY RAND() LIMIT 5");

        if (!empty($results)) {
            echo '<ul>';
            foreach ($results as $todo) {
                echo '<li>' . esc_html($todo->title) . '</li>';
            }
            echo '</ul>';
        } else {
            echo 'No todos found.';
        }
    }
}

new TodoSyncPlugin();
