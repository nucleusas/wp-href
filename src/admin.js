import { registerPlugin } from "@wordpress/plugins";
import { ComboboxControl } from "@wordpress/components";
import { useState, useEffect } from "@wordpress/element";
import { useSelect, useDispatch } from "@wordpress/data";
import { __ } from "@wordpress/i18n";
import { PluginDocumentSettingPanel } from "@wordpress/editor";

const HreflangPanel = () => {
  const [options, setOptions] = useState([]);
  const [isLoading, setIsLoading] = useState(false);

  const mainSiteRelation = useSelect((select) => {
    return (
      select("core/editor").getEditedPostAttribute("meta")?.hreflang_relation ||
      ""
    );
  });

  const { editPost } = useDispatch("core/editor");

  useEffect(() => {
    if (mainSiteRelation) {
      fetchPostDetails(mainSiteRelation);
    }
  }, [mainSiteRelation]);

  const fetchPostDetails = async (postId) => {
    try {
      const response = await fetch(
        `${wpHreflangSettings.root}/main-site-post/${postId}`,
        {
          headers: {
            "X-WP-Nonce": wpHreflangSettings.nonce,
          },
          credentials: "same-origin",
        }
      );

      const post = await response.json();

      setOptions([
        {
          value: post.id,
          label: post.title,
        },
      ]);
    } catch (error) {
      console.error("Error fetching post details:", error);
    }
  };

  const searchPosts = async (searchTerm) => {
    if (!searchTerm || searchTerm.length < 3) {
      setOptions([]);
      return;
    }

    setIsLoading(true);
    try {
      const response = await fetch(
        `${wpHreflangSettings.root}/search?query=${encodeURIComponent(
          searchTerm
        )}`,
        {
          headers: {
            "X-WP-Nonce": wpHreflangSettings.nonce,
          },
          credentials: "same-origin",
        }
      );
      const posts = await response.json();

      setOptions(
        posts.map((post) => ({
          value: post.id,
          label: post.title,
        }))
      );
    } catch (error) {
      console.error("Error searching posts:", error);
    } finally {
      setIsLoading(false);
    }
  };

  const handleChange = (value) => {
    editPost({
      meta: {
        hreflang_relation: value,
      },
    });
  };

  return (
    <PluginDocumentSettingPanel
      name="hreflang-panel"
      title={__("Link to Main Site", "wp-hreflang")}
      className="hreflang-panel"
    >
      <ComboboxControl
        label={__("Search main site content", "wp-hreflang")}
        value={mainSiteRelation || ""}
        onChange={handleChange}
        onFilterValueChange={searchPosts}
        options={options}
        isLoading={isLoading}
      />
    </PluginDocumentSettingPanel>
  );
};

registerPlugin("wp-hreflang-panel", {
  render: HreflangPanel,
  icon: "translation",
});
