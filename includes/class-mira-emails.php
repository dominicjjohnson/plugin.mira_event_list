<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MiraEmails {

    public function send_ticket( array $attendee, $booking, $event, array $extra_headers = array() ) {
        $from_name   = get_option( 'mira_ticket_from_name', get_bloginfo( 'name' ) );
        $from_email  = get_option( 'mira_ticket_from_email', get_option( 'admin_email' ) );
        $subject_tpl = get_option( 'mira_ticket_email_subject', 'Your ticket for {event_name}' );
        $subject     = str_replace( '{event_name}', $event->post_title, $subject_tpl );

        $display_date   = get_post_meta( $event->ID, '_display_date', true );
        $event_date     = get_post_meta( $event->ID, '_event_date', true );
        $event_location = get_post_meta( $event->ID, '_event_location', true );
        $formatted_date = $display_date ?: ( $event_date ? date_i18n( 'j F Y', strtotime( $event_date ) ) : '' );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . wp_specialchars_decode( $from_name, ENT_QUOTES ) . ' <' . $from_email . '>',
        );

        if ( ! empty( $extra_headers ) ) {
            $headers = array_merge( $headers, $extra_headers );
        }

        $message = $this->build_email( $attendee, $booking, $event, $formatted_date, $event_location );
        $sent    = wp_mail( $attendee['email'], $subject, $message, $headers );

        if ( $sent && ! empty( $attendee['id'] ) ) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'mira_attendees',
                array( 'sent_at' => current_time( 'mysql' ) ),
                array( 'id' => (int) $attendee['id'] ),
                array( '%s' ),
                array( '%d' )
            );
        }

        return $sent;
    }

    private function build_email( array $attendee, $booking, $event, $formatted_date, $event_location ) {
        $accent = get_option( 'mira_event_button_color', '#28a745' );
        $site   = get_bloginfo( 'name' );

        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width,initial-scale=1">
        </head>
        <body style="margin:0;padding:0;background:#f0f0f0;font-family:Arial,Helvetica,sans-serif;">

        <table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">
        <tr><td align="center" style="padding:30px 15px;">

          <table width="600" cellpadding="0" cellspacing="0" border="0" role="presentation"
                 style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.12);">

            <!-- Header -->
            <tr>
              <td style="background:<?php echo esc_attr( $accent ); ?>;padding:28px 32px;text-align:center;">
                <p style="margin:0;color:#ffffff;font-size:22px;font-weight:700;letter-spacing:.5px;">
                  <?php echo esc_html( $site ); ?>
                </p>
              </td>
            </tr>

            <!-- Body -->
            <tr>
              <td style="padding:32px;">
                <p style="margin:0 0 16px;font-size:16px;color:#333333;">
                  <?php printf( 'Hi %s,', esc_html( $attendee['name'] ) ); ?>
                </p>
                <p style="margin:0 0 24px;font-size:15px;color:#555555;line-height:1.5;">
                  Your ticket for <strong><?php echo esc_html( $event->post_title ); ?></strong> is below.
                  Please bring this to the event — printed or on your phone.
                </p>

                <!-- Ticket block -->
                <table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation"
                       style="border:2px dashed #cccccc;border-radius:8px;background:#fafafa;margin-bottom:24px;">
                  <tr>
                    <td style="padding:28px 24px;text-align:center;">

                      <p style="margin:0 0 6px;font-size:11px;color:#999999;text-transform:uppercase;letter-spacing:2px;">
                        Ticket Number
                      </p>
                      <p style="margin:0 0 20px;font-size:26px;font-weight:700;font-family:monospace;color:#222222;letter-spacing:2px;">
                        <?php echo esc_html( $attendee['ticket_number'] ); ?>
                      </p>

                      <hr style="border:none;border-top:1px solid #e0e0e0;margin:0 0 20px;">

                      <p style="margin:0 0 8px;font-size:19px;font-weight:700;color:#333333;">
                        <?php echo esc_html( $event->post_title ); ?>
                      </p>
                      <?php if ( $formatted_date ) : ?>
                        <p style="margin:0 0 4px;font-size:14px;color:#666666;">
                          <?php echo esc_html( $formatted_date ); ?>
                        </p>
                      <?php endif; ?>
                      <?php if ( $event_location ) : ?>
                        <p style="margin:0 0 20px;font-size:14px;color:#666666;">
                          <?php echo esc_html( $event_location ); ?>
                        </p>
                      <?php else : ?>
                        <p style="margin:0 0 20px;">&nbsp;</p>
                      <?php endif; ?>

                      <hr style="border:none;border-top:1px solid #e0e0e0;margin:0 0 20px;">

                      <p style="margin:0 0 4px;font-size:11px;color:#999999;text-transform:uppercase;letter-spacing:2px;">
                        Booking Reference
                      </p>
                      <p style="margin:0 0 16px;font-size:15px;font-weight:700;font-family:monospace;color:#444444;">
                        <?php echo esc_html( $booking->booking_reference ); ?>
                      </p>

                      <p style="margin:0;font-size:14px;color:#777777;">
                        <?php echo esc_html( $attendee['name'] ); ?>
                      </p>

                    </td>
                  </tr>
                </table>

                <p style="font-size:13px;color:#999999;margin:0;">
                  If you have any questions about this event, please reply to this email.
                </p>
              </td>
            </tr>

            <!-- Footer -->
            <tr>
              <td style="background:#f8f8f8;padding:16px 32px;text-align:center;border-top:1px solid #eeeeee;">
                <p style="margin:0;font-size:12px;color:#aaaaaa;">
                  Sent to <?php echo esc_html( $attendee['email'] ); ?> &bull; <?php echo esc_html( $site ); ?>
                </p>
              </td>
            </tr>

          </table>
        </td></tr>
        </table>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}
